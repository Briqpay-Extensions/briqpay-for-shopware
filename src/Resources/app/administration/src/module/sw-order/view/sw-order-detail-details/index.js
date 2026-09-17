import template from './sw-order-detail-details.html.twig';
import './../../component/briqpay-order-info';
import './../../component/briqpay-capture';
import './../../component/briqpay-hosted-page';

Shopware.Component.override('sw-order-detail-details', {
    template
});