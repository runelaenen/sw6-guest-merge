import './init/api.init';
import './page/lae-guest-merge-tab';

// Extend sw-customer-detail with a new route + tab
Shopware.Module.register('lae-guest-merge', {
    type: 'plugin',
    name: 'LaeGuestMerge',
    title: 'lae-merge.module.title',
    description: 'lae-merge.module.description',
    color: '#0073e6',
    icon: 'regular-merge',

    routeMiddleware(next, currentRoute) {
        if (currentRoute.name === 'sw.customer.detail') {
            currentRoute.children.push({
                name: 'sw.customer.detail.laeMerge',
                path: '/sw/customer/detail/:id/lae-merge',
                component: 'lae-guest-merge-tab',
                meta: {
                    parentPath: 'sw.customer.index',
                    privilege: 'customer.editor',
                },
            });
        }
        next(currentRoute);
    },
});
