// src/Resources/app/administration/src/main.js
import './module/sw-order/view/sw-order-detail-details';
import BriqpayApiService from './core/service/api/briqpay-api.service';

Shopware.Application.addServiceProvider('briqpayApiService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');
    return new BriqpayApiService(initContainer.httpClient, Shopware.Service('loginService'));
});