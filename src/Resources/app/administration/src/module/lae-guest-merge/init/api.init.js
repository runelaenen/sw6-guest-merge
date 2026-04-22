import LaeGuestMergeApiService from '../service/api/lae-guest-merge.api.service';

const { Application } = Shopware;

Application.addServiceProvider('laeGuestMergeService', (container) => {
    const initContainer = Application.getContainer('init');
    return new LaeGuestMergeApiService(
        initContainer.httpClient,
        container.loginService
    );
});
