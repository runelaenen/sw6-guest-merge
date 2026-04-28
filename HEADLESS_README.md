# LaenenGuestMerge — Headless / Store API Reference

This document covers the Store API endpoints for headless frontends (Nuxt, Vue Storefront, custom SPA, etc.) that need to implement the guest-order merge flow without a Twig storefront.

The storefront and the Store API share a single implementation (`GuestMergeRoute`), so behaviour is identical across both channels.

---

## Prerequisites

- Plugin installed and activated
- Customer has registered an account with the same email address used for guest orders
- Customer is logged in (see Authentication below)

---

## Authentication

Every endpoint requires a logged-in customer. Pass the context token obtained during login:

```
sw-context-token: <token-from-login-response>
```

Requests without a valid token receive a `403 Forbidden` before reaching the controller.

---

## Store API Endpoints

### 1. GET `/store-api/account/laenen/merge-guest-orders`

Check whether the customer has mergeable guest orders and whether a pending merge request already exists.

**Headers**

| Header | Value |
|--------|-------|
| `sw-context-token` | Customer context token |

**Success response (200)**

```json
{
  "candidates": {
    "email": "customer@example.com",
    "guestCustomerCount": 2,
    "guestCustomerIds": ["aabbcc...", "ddeeff..."],
    "orderCount": 5,
    "totalAmount": 349.95,
    "oldestOrderDate": "2023-01-15 10:22:00.000",
    "newestOrderDate": "2024-06-01 08:00:00.000",
    "bySalesChannel": [
      {
        "salesChannelId": "...",
        "salesChannelName": "Storefront",
        "orderCount": 5,
        "totalAmount": 349.95
      }
    ]
  },
  "latestRequest": {
    "id": "aabbccdd...",
    "status": "pending",
    "verificationMethod": null,
    "candidateCount": 2,
    "orderCountSnapshot": 5,
    "movedOrderCount": null,
    "expiresAt": "2024-06-02 08:00:00.000",
    "confirmedAt": null,
    "completedAt": null,
    "createdAt": "2024-06-01 08:00:00.000",
    "errorMessage": null
  },
  "config": {
    "allowSelfService": true
  }
}
```

`latestRequest` is `null` when no request has ever been created for this customer.

`candidates.orderCount` is `0` when there is nothing to merge — show an empty state, not the initiate button.

---

### 2. POST `/store-api/account/laenen/merge-guest-orders/initiate`

Initiate a merge request. Sends a verification email containing a confirmation link and a short verbal code.

Only available when `config.allowSelfService` is `true`. If the plugin is configured for admin-only initiation, this endpoint returns `403`.

**Headers**

| Header | Value |
|--------|-------|
| `sw-context-token` | Customer context token |

**Request body** — none required

**Success response (200)**

```json
{
  "requestId": "aabbccdd...",
  "expiresAt": "2024-06-02T08:00:00+00:00",
  "candidateOrderCount": 5,
  "candidateGuestCount": 2
}
```

After success, poll `GET /store-api/account/laenen/merge-guest-orders` to show the "pending — check your inbox" state.

**Error codes**

| Code | HTTP | Meaning |
|------|------|---------|
| `LAENEN_GUEST_MERGE__SELF_SERVICE_DISABLED` | 403 | Plugin configured for admin-only initiation |
| `LAENEN_GUEST_MERGE__NO_CANDIDATES` | 404 | No guest orders found for this email |

---

### 3. GET `/store-api/account/laenen/merge-guest-orders/confirm/{token}`

Validate a confirmation token (from the verification email link) and preview the pending merge before the customer confirms it.

The `{token}` is the 64-character hex token embedded in the email link.

**Headers**

| Header | Value |
|--------|-------|
| `sw-context-token` | Customer context token |

**Success response (200)**

```json
{
  "token": "aabbccdd...64chars",
  "request": {
    "id": "aabbccdd...",
    "status": "pending",
    "verificationMethod": null,
    "candidateCount": 2,
    "orderCountSnapshot": 5,
    "movedOrderCount": null,
    "expiresAt": "2024-06-02 08:00:00.000",
    "confirmedAt": null,
    "completedAt": null,
    "createdAt": "2024-06-01 08:00:00.000",
    "errorMessage": null
  },
  "candidates": {
    "email": "customer@example.com",
    "guestCustomerCount": 2,
    "guestCustomerIds": ["..."],
    "orderCount": 5,
    "totalAmount": 349.95,
    "oldestOrderDate": "2023-01-15 10:22:00.000",
    "newestOrderDate": "2024-06-01 08:00:00.000",
    "bySalesChannel": []
  }
}
```

**Error codes**

| Code | HTTP | Meaning |
|------|------|---------|
| `LAENEN_GUEST_MERGE__INVALID_TOKEN` | 410 | Token unknown, already used, or belongs to a different customer |
| `LAENEN_GUEST_MERGE__REQUEST_EXPIRED` | 410 | Token has expired — customer must initiate again |

---

### 4. POST `/store-api/account/laenen/merge-guest-orders/confirm/{token}`

Execute the merge. Marks the request as confirmed, re-links all guest orders to the authenticated account, and sends a completion email.

This operation is idempotent against double-submission: a second `POST` with the same token returns `LAENEN_GUEST_MERGE__INVALID_TOKEN` (the request is no longer `pending`).

**Headers**

| Header | Value |
|--------|-------|
| `sw-context-token` | Customer context token |

**Request body** — none required

**Success response (200)**

```json
{
  "result": {
    "requestId": "aabbccdd...",
    "authCustomerId": "11223344...",
    "email": "customer@example.com",
    "movedOrderCount": 5,
    "movedOrderIds": ["order-id-1", "order-id-2"],
    "deletedGuestCustomerIds": ["guest-id-1", "guest-id-2"],
    "verificationMethod": "link"
  }
}
```

After success, the merged orders are visible under the customer's account.

**Error codes**

| Code | HTTP | Meaning |
|------|------|---------|
| `LAENEN_GUEST_MERGE__INVALID_TOKEN` | 410 | Token already used or belongs to a different customer |
| `LAENEN_GUEST_MERGE__REQUEST_EXPIRED` | 410 | Token expired before confirmation |
| `LAENEN_GUEST_MERGE__GENERAL_ERROR` | 500 | Merge execution failed — see `errorMessage` in a subsequent status call |

---

## Error Envelope Format

All errors use the standard Shopware error envelope:

```json
{
  "errors": [
    {
      "status": "410",
      "code": "LAENEN_GUEST_MERGE__INVALID_TOKEN",
      "title": "Gone",
      "detail": "Invalid or unknown token."
    }
  ]
}
```

---

## Typical Headless Flow

```
1.  GET  /store-api/account/laenen/merge-guest-orders
        → check candidates.orderCount > 0 AND config.allowSelfService
        → check latestRequest.status != 'pending' (no existing pending request)

2.  POST /store-api/account/laenen/merge-guest-orders/initiate
        → show "check your inbox" UI

3.  Customer opens the email and clicks the confirmation link.
    The link URL contains the token:
      /account/merge-guest-orders/confirm/{token}
    (storefront) or your SPA intercepts and extracts {token}.

4.  GET  /store-api/account/laenen/merge-guest-orders/confirm/{token}
        → display a confirmation screen with order count / total

5.  POST /store-api/account/laenen/merge-guest-orders/confirm/{token}
        → show success screen with result.movedOrderCount
```

---

## Config Flags That Affect Headless Behaviour

| Flag | Default | Effect |
|------|---------|--------|
| `allowSelfServiceInitiation` | `true` | If `false`, `POST /initiate` returns 403. Show contact-support UI instead. |
| `restrictToSameSalesChannel` | `false` | If `true`, only guest orders from the same sales channel are considered candidates. |
| `sendCompletionEmail` | `true` | Controls whether the customer receives a "merge complete" email after step 5. |

Configure under *Settings → Plugins → Laenen Guest Merge*.

---

## Events

These events fire identically regardless of whether the flow is triggered via the Store API, the storefront, or the admin API:

| Event | When |
|-------|------|
| `Laenen\GuestMerge\Event\GuestOrderMergeRequestedEvent` | After a merge request is created (step 2) |
| `Laenen\GuestMerge\Event\GuestOrderMergeConfirmedEvent` | After the request is marked confirmed (step 5, before execution) |
| `Laenen\GuestMerge\Event\GuestOrderMergedEvent` | After the merge completes successfully (step 5) |

`GuestOrderMergedEvent` carries `movedOrderIds` and `deletedGuestIds` — use this to trigger downstream re-syncs.
