import template from './lae-guest-merge-tab.html.twig';
import './lae-guest-merge-tab.scss';

Shopware.Component.register('lae-guest-merge-tab', {
    template,

    inject: ['laeGuestMergeService', 'acl'],

    data() {
        return {
            isLoading: true,
            isProcessing: false,
            preview: null,
            verbalCode: '',
            customerId: null,
        };
    },

    computed: {
        candidateOrderCount() {
            return this.preview?.candidates?.orderCount ?? 0;
        },
        candidateGuestCount() {
            return this.preview?.candidates?.guestCustomerCount ?? 0;
        },
        latest() {
            return this.preview?.latestRequest ?? null;
        },
        hasPending() {
            return this.latest?.status === 'pending';
        },
        hasCompleted() {
            return this.latest?.status === 'completed';
        },
        hasFailed() {
            return this.latest?.status === 'failed';
        },
        canDirectMerge() {
            return !!this.preview?.capabilities?.allowDirectMergeForTrustedCsr;
        },
    },

    created() {
        this.customerId = this.$route.params.id;
        this.loadPreview();
    },

    methods: {
        async loadPreview() {
            this.isLoading = true;
            try {
                this.preview = await this.laeGuestMergeService.preview(this.customerId);
            } catch (e) {
                this.notifyError(this.errorMessage(e));
            } finally {
                this.isLoading = false;
            }
        },
        async sendVerification() {
            this.isProcessing = true;
            try {
                await this.laeGuestMergeService.initiate(this.customerId);
                this.notifySuccess(this.$tc('lae-merge.notify.sent'));
                await this.loadPreview();
            } catch (e) {
                this.notifyError(this.errorMessage(e));
            } finally {
                this.isProcessing = false;
            }
        },
        async submitCode() {
            if (!this.verbalCode) return;
            this.isProcessing = true;
            try {
                const res = await this.laeGuestMergeService.verifyCode(
                    this.customerId, this.verbalCode
                );
                this.notifySuccess(this.$tc('lae-merge.notify.merged', 0, {
                    count: res.result?.movedOrderCount ?? 0,
                }));
                this.verbalCode = '';
                await this.loadPreview();
            } catch (e) {
                this.notifyError(this.errorMessage(e));
            } finally {
                this.isProcessing = false;
            }
        },
        async cancelPending() {
            this.isProcessing = true;
            try {
                await this.laeGuestMergeService.cancel(this.customerId);
                this.notifySuccess(this.$tc('lae-merge.notify.cancelled'));
                await this.loadPreview();
            } catch (e) {
                this.notifyError(this.errorMessage(e));
            } finally {
                this.isProcessing = false;
            }
        },
        async directMerge() {
            if (!confirm(this.$tc('lae-merge.directMerge.confirm'))) return;
            this.isProcessing = true;
            try {
                const res = await this.laeGuestMergeService.directMerge(this.customerId);
                this.notifySuccess(this.$tc('lae-merge.notify.merged', 0, {
                    count: res.result?.movedOrderCount ?? 0,
                }));
                await this.loadPreview();
            } catch (e) {
                this.notifyError(this.errorMessage(e));
            } finally {
                this.isProcessing = false;
            }
        },
        formatCurrency(amount) {
            const n = Number(amount ?? 0);
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(n);
        },
        formatDate(d) {
            if (!d) return '-';
            return new Date(d).toLocaleString();
        },
        notifySuccess(message) {
            this.createNotificationSuccess({ message });
        },
        notifyError(message) {
            this.createNotificationError({ message });
        },
        errorMessage(e) {
            return e?.response?.data?.error
                ?? e?.response?.data?.errors?.[0]?.detail
                ?? e?.message
                ?? this.$tc('lae-merge.notify.error');
        },
    },
});
