// src/core/service/api/briqpay-api.service.js
const ApiService = Shopware.Classes.ApiService;

class BriqpayApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'briqpay') {
        super(httpClient, loginService, apiEndpoint);
    }

    capture(payload) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(`_action/${this.getApiBasePath()}/capture`, payload, {
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    refund(payload) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(`_action/${this.getApiBasePath()}/refund`, payload, {
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    cancel(payload) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(`_action/${this.getApiBasePath()}/cancel`, payload, {
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    createHostedPage(payload) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(`_action/${this.getApiBasePath()}/hosted-page`, payload, {
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    list(transactionId) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .get(`_action/${this.getApiBasePath()}/list/${transactionId}`, {
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    getBasicHeaders() {
        if (!this.loginService || !this.loginService.getToken()) {
            return {};
        }

        return super.getBasicHeaders();
    }
}

export default BriqpayApiService;