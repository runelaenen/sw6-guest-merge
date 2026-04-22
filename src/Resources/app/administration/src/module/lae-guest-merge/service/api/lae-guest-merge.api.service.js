const ApiService = Shopware.Classes.ApiService;

class LaeGuestMergeApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'laenen/guest-merge') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'laeGuestMergeService';
    }

    preview(customerId) {
        return this.httpClient
            .get(`/_action/${this.getApiBasePath()}/preview/${customerId}`, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    initiate(customerId) {
        return this.httpClient
            .post(`/_action/${this.getApiBasePath()}/initiate/${customerId}`, {}, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    verifyCode(customerId, code) {
        return this.httpClient
            .post(`/_action/${this.getApiBasePath()}/verify-code/${customerId}`,
                { code },
                { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    cancel(customerId) {
        return this.httpClient
            .post(`/_action/${this.getApiBasePath()}/cancel/${customerId}`, {}, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    directMerge(customerId) {
        return this.httpClient
            .post(`/_action/${this.getApiBasePath()}/direct-merge/${customerId}`, {}, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }
}

export default LaeGuestMergeApiService;
