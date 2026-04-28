# Guest Merge plugin for Shopware 6

Merge guest orders into authenticated customer accounts with verified inbox ownership, while keeping Shopware core intact.

## What it does

When a customer who previously checked out as a guest later creates (or already
has) an authenticated account using the same email address, this plugin moves
those guest orders under the authenticated account so they appear in order
history, contribute to lifetime value metrics, and can be acted on from the
admin panel.

## Three merge paths (per the requirements thread)

| Path | Who initiates | Verification | When to use |
|------|---------------|--------------|-------------|
| **Email link** (storefront) | Customer (self-service) or CSR via admin | Customer clicks confirmation link | Default & most common |
| **Verbal code** (admin) | CSR via admin tab | CSR enters code customer reads from email aloud | Phone support |
| **Trusted CSR direct** (admin, gated) | CSR via admin tab | None - audit trail only | Long-standing customers, identity already established |

The third path is **off by default**. Enable it under
*Settings → Plugins → Laenen Guest Merge → CSR / Admin* and grant the
`laenen_guest_merge.trusted` privilege only to CSRs you trust to verify
identity by other means.

## Installation

```bash
# Place the plugin folder under custom/plugins/
cp -R LaenenGuestMerge custom/plugins/
# Or install via composer
composer require laenen/sw6-guest-merge

# Refresh and install
bin/console plugin:refresh
bin/console plugin:install --activate LaenenGuestMerge
bin/console cache:clear

# Build assets
./bin/build-js.sh
bin/console theme:compile
```

## What gets updated on 'Merge'?

| Operation | Table | Why |
|-----------|-------|-----|
| `UPDATE` `customer_id` | `order_customer` | Re-link orders to auth customer |
| `UPDATE` aggregates | `customer` (auth row) | Recompute order_count, order_total_amount, last_order_date |
| `DELETE` (DAL) | `customer` (guest rows) | Remove now-orphaned guest customer records (cascades guest addresses, customer_recovery, etc.) |

## What is NOT touched on 'Merge'?

- `customer.default_billing_address_id`, `default_shipping_address_id`,
  `default_payment_method_id` of the authenticated account
- The authenticated account's address book or saved payment methods
- The `order`, `order_address`, `order_transaction`, `order_line_item` rows
- Order snapshot fields on `order_customer` (email, first/last name,
  customer_number) - these stay as the buyer entered them at checkout

## Configuration reference

See `src/Resources/config/config.xml`.  
Key settings:

- **tokenLifetimeHours** (default `24`) - how long verification links/codes stay valid
- **restrictToSameSalesChannel** (default `false`) - restrict to same SC as auth account
- **sendCompletionEmail** (default `true`) - send "merge complete" mail
- **allowSelfServiceInitiation** (default `true`) - show storefront entry under My Account
- **showRegistrationHint** (default `true`) - flash message after register if guest orders exist
- **allowDirectMergeForTrustedCsr** (default `false`) - enable CSR override path

## Events for downstream integrations

- `Laenen\GuestMerge\Event\GuestOrderMergeRequestedEvent`
- `Laenen\GuestMerge\Event\GuestOrderMergeConfirmedEvent`
- `Laenen\GuestMerge\Event\GuestOrderMergedEvent` (final, post-merge)

After a merge, downstream systems should re-sync the auth customer profile and
the affected orders. The `GuestOrderMergedEvent` payload includes
`movedOrderIds` and `deletedGuestIds`.

## Admin API quick reference

```
GET  /api/_action/laenen/guest-merge/preview/{customerId}
POST /api/_action/laenen/guest-merge/initiate/{customerId}
POST /api/_action/laenen/guest-merge/verify-code/{customerId}    body: { "code": "ABCD2345" }
POST /api/_action/laenen/guest-merge/cancel/{customerId}
POST /api/_action/laenen/guest-merge/direct-merge/{customerId}   ACL: laenen_guest_merge.trusted
```

## Storefront routes

```
GET  /account/merge-guest-orders                       - self-service entry page
POST /account/merge-guest-orders/initiate              - send verification email
GET  /account/merge-guest-orders/confirm/{token}       - confirmation landing page
POST /account/merge-guest-orders/confirm/{token}       - execute merge
```
