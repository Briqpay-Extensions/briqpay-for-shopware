const { Component } = Shopware;
import template from './briqpay-order-info.html.twig';

/**
 * Renders supplementary Briqpay order details (company info, PSP metadata,
 * strong-auth output, backoffice deep link) below the native order details.
 *
 * This is a plain Vue component rendered via a Twig block append in
 * sw-order-detail-details.html.twig — it does NOT attempt to relocate itself
 * into other parts of the admin DOM. An earlier version used setInterval +
 * document.querySelector against a hardcoded Swedish-locale CSS selector
 * (article[aria-label="Betalningsmetod"]) to move itself and to rewrite the
 * payment method name; both broke on any non-Swedish admin locale and were
 * redundant with PaymentNameSubscriber.php, which already overrides the
 * payment method name server-side before the admin API response is built.
 */
Component.register('briqpay-order-info', {
    template,
    props: {
        order: { type: Object, required: true }
    },
    computed: {
        briqpayData() { return this.order?.customFields || {}; },
        backofficeUrl() {
            const sid = this.briqpayData.briqpay_session_id;
            const mid = this.briqpayData.briqpay_merchant_id;
            const test = this.briqpayData.briqpay_test_mode || '1';

            if (!sid || !mid) return null;

            return `https://app.briqpay.com/dashboard/sessions/orders/${sid}?test=${test}&merchantId=${mid}`;
        }
    }
});
