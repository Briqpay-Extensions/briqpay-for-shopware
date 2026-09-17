import template from './briqpay-capture.html.twig';
import './briqpay-capture.scss';

const { Component, Context, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('briqpay-capture', {
    template,

    inject: ['repositoryFactory', 'briqpayApiService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        order: {
            type: Object,
            required: true
        }
    },

    data() {
        return {
            captures: [],
            isLoading: false,
            showCaptureModal: false,
            showRefundModal: false,
            orderItems: [],
            refundItems: [],
            refundingCapture: null, // The capture record being refunded
            // The transaction state as the server last reported it, refreshed
            // with every records load. The `order` prop is only as fresh as the
            // page, so after a cancel or capture it would still say the old
            // state until the order is reloaded.
            liveTransactionState: null
        };
    },

    computed: {
        transaction() {
            return this.order.transactions ? this.order.transactions.last() : null;
        },

        transactionState() {
            if (this.liveTransactionState) {
                return this.liveTransactionState;
            }

            return this.transaction && this.transaction.stateMachineState
                ? this.transaction.stateMachineState.technicalName
                : null;
        },

        /**
         * Briqpay has not approved the order yet: nothing can be captured,
         * refunded or cancelled until the order_status webhook says so. Same
         * states BriqpayCaptureService refuses server-side.
         */
        isPending() {
            return ['open', 'in_progress', 'unconfirmed', 'reminded'].includes(this.transactionState);
        },

        isManualReview() {
            return this.transactionState === 'reminded';
        },

        allowRefund() {
            return this.isBriqpayOrder
                && ['paid', 'paid_partially', 'refunded_partially'].includes(this.transactionState);
        },

        captureRecords() {
            if (!this.captures || !this.captures.length) return [];
            return [...this.captures].filter(c => c.type === 'capture' || !c.type);
        },

        refundRecords() {
            if (!this.captures || !this.captures.length) return [];
            return [...this.captures].filter(c => c.type === 'refund');
        },

        isBriqpayOrder() {
            if (!this.transaction) {
                return false;
            }

            const paymentMethod = this.transaction.paymentMethod || {};
            const isBriqpayHandler = paymentMethod.handlerIdentifier === 'Briqpay\\Payments\\Payment\\BriqpayPaymentHandler';
            const hasBriqpaySession =
                (this.transaction.customFields && this.transaction.customFields.briqpay_session_id)
                || (this.order.customFields && this.order.customFields.briqpay_session_id);

            return isBriqpayHandler || !!hasBriqpaySession;
        },

        isFullyCaptured() {
            const orderTotal = this.order.amountTotal || 0;
            // Compare in integer cents with a small bounded tolerance, not an
            // exact float match. Confirmed live: splitting a capture across
            // multiple partial calls can leave the sum a few genuine cents
            // short of Shopware's own order total (each partial capture's
            // rounding doesn't necessarily reconstitute what a single one-shot
            // rounding of the whole line would have produced) even though
            // Briqpay's own side already reports the order as fully captured —
            // matches the same tolerance BriqpayCaptureService::
            // transitionAfterCapture() applies server-side, so the "Capture"
            // button doesn't stay stuck visible for an order that's actually done.
            const ROUNDING_TOLERANCE_CENTS = 5;
            return orderTotal > 0
                && Math.round(this.totalCaptured * 100) >= Math.round(orderTotal * 100) - ROUNDING_TOLERANCE_CENTS;
        },

        allowCapture() {
            if (!this.isBriqpayOrder || this.isFullyCaptured) {
                return false;
            }

            // Only an approved authorisation, or one partly captured already,
            // has anything left to capture. Same set as the server enforces.
            return ['authorized', 'paid_partially'].includes(this.transactionState);
        },

        totalCaptured() {
            return this.captureRecords.reduce((sum, c) => sum + (c.amount || 0), 0);
        },

        /**
         * Cancel (void) is only valid before any capture has been made — mirrors the
         * guard Briqpay's other integrations enforce. Once anything is captured,
         * a refund must be used instead.
         */
        allowCancel() {
            if (!this.isBriqpayOrder || this.captureRecords.length > 0) {
                return false;
            }

            return this.transactionState === 'authorized';
        },

        totalRefunded() {
            return this.refundRecords.reduce((sum, c) => sum + (c.amount || 0), 0);
        },

        /**
         * Map of captureId → total refunded amount for that capture
         */
        refundedByCapture() {
            const map = {};
            this.refundRecords.forEach(ref => {
                const parentId = ref.parentCaptureId;
                if (parentId) {
                    map[parentId] = (map[parentId] || 0) + (ref.amount || 0);
                }
            });
            return map;
        },

        /**
         * Map of captureId → refunded items { reference → quantity }
         */
        refundedItemsByCapture() {
            const map = {};
            this.refundRecords.forEach(ref => {
                const parentId = ref.parentCaptureId;
                if (!parentId || !ref.items) return;
                if (!map[parentId]) map[parentId] = {};
                ref.items.forEach(item => {
                    const refKey = item.reference;
                    map[parentId][refKey] = (map[parentId][refKey] || 0) + (item.quantity || 0);
                });
            });
            return map;
        },

        allCaptureItemsSelected() {
            return this.orderItems.every(item => item.selected || item.remainingQuantity <= 0);
        },

        allRefundItemsSelected() {
            return this.refundItems.every(item => item.selected || item.refundableQuantity <= 0);
        },

        // Computed rather than a manually-updated data property: previously
        // these only recalculated when an @change handler fired on the
        // quantity input, which could go stale — the modal's displayed total
        // and the request actually sent could diverge (the request itself
        // reads item.captureQuantity directly and was always correct; only
        // the on-screen total risked being wrong, confirmed live when editing
        // the quantity field left "Total" showing the pre-edit amount). A
        // computed property can't go stale like that — it re-derives from
        // orderItems/refundItems on every access.
        captureAmount() {
            return parseFloat(this.calculateItemTotal(this.orderItems).toFixed(2));
        },

        refundAmount() {
            return parseFloat(this.calculateItemTotal(this.refundItems).toFixed(2));
        }
    },

    created() {
        this.loadCaptures();
    },

    methods: {
        async loadCaptures() {
            if (!this.transaction) return;
            
            this.isLoading = true;

            try {
                const response = await this.briqpayApiService.list(this.order.id);
                if (response.success) {
                    this.captures = response.records || [];
                    this.liveTransactionState = response.transactionState || null;
                } else {
                    this.createNotificationError({
                        title: 'Briqpay Error',
                        message: response.message || 'Failed to load captures'
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    title: 'Briqpay Error',
                    message: error.response?.data?.message || error.message
                });
                console.error('Briqpay: Error loading captures', error);
            } finally {
                this.isLoading = false;
            }
        },

        /**
         * The reference the Briqpay session knows this order line by. Must
         * match BriqpayRequestFactory::resolveReference(): product number for
         * products, "discount_<code>" for promotions -- a capture is matched
         * to session lines by this, and an unknown reference fails the whole
         * capture (CART_ITEM_NOT_FOUND).
         */
        lineItemReference(item) {
            if (item.type === 'promotion') {
                const code = item.payload?.code || item.referencedId || item.payload?.promotionId || 'promotion';
                return `discount_${code}`;
            }

            // A variant's product number carries a suffix after a dot
            // ("SWDEMO10005.1"); Briqpay knows the line by the part before it,
            // the same normalisation BriqpayRequestFactory applies.
            const raw = item.payload?.productNumber || item.identifier || '';
            return String(raw).split('.')[0];
        },

        buildOrderItems() {
            // Calculate already captured quantities
            const capturedQtys = {};
            this.captureRecords.forEach(cap => {
                if (!cap.items) return;
                cap.items.forEach(item => {
                    const ref = item.reference;
                    capturedQtys[ref] = (capturedQtys[ref] || 0) + (item.quantity || 0);
                });
            });

            const items = this.order.lineItems.map(item => {
                const ref = this.lineItemReference(item);
                const captured = capturedQtys[ref] || 0;
                const remaining = Math.max(0, item.quantity - captured);

                return {
                    ...item,
                    quantity: item.quantity,
                    remainingQuantity: remaining,
                    captureQuantity: remaining,
                    selected: remaining > 0,
                    // Keep the resolved Briqpay reference on the row. `type` is
                    // overwritten below, so re-deriving it later would no longer
                    // see that this was a promotion and would fall back to the
                    // line item's UUID — which Briqpay rejects with
                    // CART_ITEM_NOT_FOUND, confirmed live on a discounted cart.
                    reference: ref,
                    type: 'product'
                };
            });

            // Resolve shipping costs
            const firstDelivery = this.order.deliveries && this.order.deliveries.length > 0
                ? this.order.deliveries[0]
                : null;

            const shippingCosts = (firstDelivery && firstDelivery.shippingCosts)
                || this.order.shippingCosts
                || null;

            const shippingTotal = shippingCosts && typeof shippingCosts.totalPrice === 'number'
                ? shippingCosts.totalPrice
                : 0;

            // Only offer a "Shipping" line when it was actually part of the Briqpay
            // session's cart in the first place. BriqpayRequestFactory::mapItems()
            // (used when the session was created) skips the shipping line entirely
            // when its cost is 0 — including it here regardless would try to
            // capture a cart item Briqpay has never heard of, failing the whole
            // capture with CART_ITEM_NOT_FOUND (confirmed live against a real
            // free-shipping order).
            if (shippingCosts && shippingTotal > 0) {
                const capturedShipping = capturedQtys['shipping'] || 0;
                const remainingShipping = Math.max(0, 1 - capturedShipping);

                items.push({
                    identifier: 'shipping',
                    label: 'Shipping',
                    unitPrice: shippingTotal,
                    quantity: 1,
                    remainingQuantity: remainingShipping,
                    captureQuantity: remainingShipping,
                    selected: remainingShipping > 0,
                    type: 'shipping_fee',
                    price: shippingCosts
                });
            }

            return items;
        },

        /**
         * Build refund items from a specific capture's items,
         * accounting for already-refunded quantities against that capture.
         */
        buildRefundItemsForCapture(capture) {
            if (!capture || !capture.items) return [];

            const captureId = capture.briqpayCaptureId;
            const refundedItems = this.refundedItemsByCapture[captureId] || {};

            return capture.items.map(item => {
                const alreadyRefunded = refundedItems[item.reference] || 0;
                const refundable = Math.max(0, (item.quantity || 0) - alreadyRefunded);

                // item.unitPrice is NET in cents. Calculate GROSS for display.
                const taxFactor = 1 + (item.taxRate || 0) / 10000;
                const unitPriceGross = (item.unitPrice * taxFactor) / 100;

                return {
                    identifier: item.reference,
                    label: item.name || item.reference,
                    unitPrice: unitPriceGross,
                    quantity: item.quantity || 0,
                    refundableQuantity: refundable,
                    captureQuantity: refundable,
                    selected: refundable > 0,
                    type: item.productType || item.type || 'physical',
                    reference: item.reference,
                    taxRate: item.taxRate || 0,
                    originalItem: item
                };
            });
        },

        onOpenCaptureModal() {
            this.orderItems = this.buildOrderItems();
            this.showCaptureModal = true;
        },

        onOpenRefundForCapture(capture) {
            this.refundingCapture = capture;
            this.refundItems = this.buildRefundItemsForCapture(capture);
            this.showRefundModal = true;
        },

        /**
         * Check if a capture has refundable items remaining
         */
        captureHasRefundable(capture) {
            if (!capture || !capture.items) return false;
            const captureId = capture.briqpayCaptureId;
            const refundedItems = this.refundedItemsByCapture[captureId] || {};
            
            return capture.items.some(item => {
                const alreadyRefunded = refundedItems[item.reference] || 0;
                return (item.quantity || 0) - alreadyRefunded > 0;
            });
        },

        /**
         * Get the refund amount remaining for a capture
         */
        captureRefundedAmount(capture) {
            return this.refundedByCapture[capture.briqpayCaptureId] || 0;
        },

        calculateItemTotal(items) {
            return items
                .filter(i => i.selected)
                .reduce((sum, i) => {
                    const qty = typeof i.captureQuantity === 'number' ? i.captureQuantity : i.remainingQuantity;
                    return sum + (i.unitPrice * qty);
                }, 0);
        },

        buildPayloadItems(items) {
            return items.filter(i => i.selected).map(i => {
                const isShipping = i.identifier === 'shipping' || i.type === 'shipping_fee';
                const ref = isShipping ? 'shipping' : (i.reference || this.lineItemReference(i));

                const taxes = isShipping ? (this.order.shippingCosts?.calculatedTaxes || []) : (i.price?.calculatedTaxes || []);
                const taxRate = taxes.length > 0 ? taxes[0].taxRate : 0;
                const lineTax = taxes.reduce((sum, t) => sum + (t.tax || 0), 0);

                // The captured amount is a proportional slice of the line as
                // Shopware priced it, not a rounded unit price multiplied back
                // up. Capturing every unit then reproduces the session's line
                // exactly, which is what Briqpay reconciles the capture against
                // — deriving it from a per-unit price instead put the capture a
                // minor unit away from the session and Briqpay refused it.
                const originalQty = i.quantity || 1;
                const share = (i.captureQuantity || 0) / originalQty;
                const lineTotalGross = typeof i.totalPrice === 'number' ? i.totalPrice : (i.unitPrice * originalQty);

                const totalAmount = Math.round(lineTotalGross * share * 100);
                const totalVatAmount = Math.round(lineTax * share * 100);

                return {
                    reference: ref,
                    name: i.label,
                    quantity: i.captureQuantity,
                    unitPrice: Math.round((totalAmount - totalVatAmount) / (i.captureQuantity || 1)),
                    taxRate: Math.round(taxRate * 100),
                    totalAmount,
                    totalVatAmount,
                    type: isShipping ? 'shipping_fee' : 'physical'
                };
            });
        },

        /**
         * Build refund payload items from capture items (already in minor units)
         */
        buildRefundPayloadItems(items) {
            return items.filter(i => i.selected && i.captureQuantity > 0).map(i => {
                return {
                    reference: i.reference,
                    name: i.label,
                    quantity: i.captureQuantity,
                    unitPrice: i.originalItem.unitPrice,
                    taxRate: i.originalItem.taxRate || 0,
                    type: i.type || 'physical'
                };
            });
        },

        async onPerformCapture() {
            this.isLoading = true;
            
            const payload = {
                transactionId: this.transaction.id,
                amount: this.captureAmount,
                items: this.buildPayloadItems(this.orderItems)
            };

            try {
                const response = await this.briqpayApiService.capture(payload);
                if (response.success) {
                    this.createNotificationSuccess({ title: 'Briqpay', message: 'Capture successful' });
                    this.showCaptureModal = false;
                    await this.loadCaptures();
                    this.$emit('order-change');
                }
            } catch (error) {
                this.createNotificationError({
                    title: 'Capture Failed',
                    message: error.response?.data?.message || error.message
                });
            } finally {
                this.isLoading = false;
            }
        },

        async onPerformRefund() {
            if (!this.refundingCapture) return;

            this.isLoading = true;
            
            const payload = {
                transactionId: this.transaction.id,
                captureId: this.refundingCapture.briqpayCaptureId,
                amount: this.refundAmount,
                items: this.buildRefundPayloadItems(this.refundItems)
            };

            try {
                const response = await this.briqpayApiService.refund(payload);
                if (response.success) {
                    this.createNotificationSuccess({ title: 'Briqpay', message: 'Refund successful' });
                    this.showRefundModal = false;
                    this.refundingCapture = null;
                    await this.loadCaptures();
                    this.$emit('order-change');
                }
            } catch (error) {
                this.createNotificationError({
                    title: 'Refund Failed',
                    message: error.response?.data?.message || error.message
                });
            } finally {
                this.isLoading = false;
            }
        },

        async onPerformCancel() {
            // eslint-disable-next-line no-alert
            if (!window.confirm('Cancel this Briqpay order? This cannot be undone.')) {
                return;
            }

            this.isLoading = true;

            try {
                const response = await this.briqpayApiService.cancel({ transactionId: this.transaction.id });
                if (response.success) {
                    this.createNotificationSuccess({ title: 'Briqpay', message: 'Order cancelled' });
                    await this.loadCaptures();
                    this.$emit('order-change');
                }
            } catch (error) {
                this.createNotificationError({
                    title: 'Cancel Failed',
                    message: error.response?.data?.message || error.message
                });
            } finally {
                this.isLoading = false;
            }
        },

        formatDate(dateString) {
            if (!dateString) return '';
            const d = new Date(dateString);
            return d.toLocaleDateString('sv-SE') + ' ' + d.toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' });
        },

        toggleAllItems(checked) {
            this.orderItems.forEach(item => {
                if (item.remainingQuantity > 0) {
                    item.selected = checked;
                }
            });
        },

        toggleAllRefundItems(checked) {
            this.refundItems.forEach(item => {
                if (item.refundableQuantity > 0) {
                    item.selected = checked;
                }
            });
        }
    }
});