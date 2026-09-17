import template from './briqpay-hosted-page.html.twig';

const { Component, Mixin } = Shopware;

/**
 * "Send a Briqpay payment link" action for orders that don't yet have a
 * Briqpay session — e.g. a manual/phone order, or an order whose original
 * payment attempt with a different method failed. Creates a Briqpay hosted
 * page and shows the resulting link for the merchant to copy/send.
 *
 * Deliberately hidden once the order already has a Briqpay session (that
 * case is handled by briqpay-capture/briqpay-order-info instead) or once
 * money has already moved (the backend also enforces this — see
 * BriqpayHostedPageService::assertHostedPageAllowed()).
 */
Component.register('briqpay-hosted-page', {
    template,

    inject: ['briqpayApiService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        order: { type: Object, required: true }
    },

    data() {
        return {
            isLoading: false,
            createdPageUrl: null
        };
    },

    computed: {
        hasExistingBriqpaySession() {
            return !!(this.order?.customFields?.briqpay_session_id);
        },

        existingHostedPageUrl() {
            return this.order?.customFields?.briqpay_hosted_page_url || null;
        },

        transactionState() {
            const transaction = this.order?.transactions?.last?.();
            return transaction?.stateMachineState?.technicalName || null;
        },

        blockedState() {
            return ['paid', 'paid_partially', 'refunded', 'refunded_partially'].includes(this.transactionState);
        },

        /**
         * Shown while the order has no Briqpay session at all, and kept on
         * screen once a payment link exists so the merchant can still copy it
         * after the page reloads (creating the link stores a session id on the
         * order, which would otherwise hide this card immediately).
         */
        shouldShow() {
            if (this.blockedState) {
                return false;
            }

            return !this.hasExistingBriqpaySession || !!this.existingHostedPageUrl;
        },

        linkToShow() {
            return this.createdPageUrl || this.existingHostedPageUrl;
        }
    },

    methods: {
        async onCreateHostedPage() {
            this.isLoading = true;

            try {
                const response = await this.briqpayApiService.createHostedPage({ orderId: this.order.id });
                if (response.success) {
                    this.createdPageUrl = response.pageUrl;
                    this.createNotificationSuccess({ title: 'Briqpay', message: 'Payment link created' });
                    this.$emit('order-change');
                }
            } catch (error) {
                this.createNotificationError({
                    title: 'Briqpay',
                    message: error.response?.data?.message || error.message
                });
            } finally {
                this.isLoading = false;
            }
        },

        async onCopyLink() {
            if (!this.linkToShow) return;
            try {
                await navigator.clipboard.writeText(this.linkToShow);
                this.createNotificationSuccess({ title: 'Briqpay', message: 'Link copied to clipboard' });
            } catch (error) {
                // Clipboard API can be unavailable (e.g. insecure context) — the link
                // is still visible and selectable, so this is a soft failure.
            }
        }
    }
});
