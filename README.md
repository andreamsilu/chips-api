# MloExpress REST API

A Laravel modular monolith for customer food orders, approved vendor kitchens, mobile-money payments, cash pickup, riders and financial operations. PHP 8.4+, MySQL 8 and Redis are the production targets. The existing welcome page and baseline Laravel migrations are preserved.

The backend implementation is ready for client integration and provider certification. It is **not deployed or certified for live money movement**; release gates are recorded in [operations](docs/operations.md).

## Design and source map

Read the [repository inspection, architecture, ERD and migration plan](docs/architecture.md) and [deployment/recovery guide](docs/operations.md). The design was documented before the domain implementation.

| Module | Source | Responsibility |
| --- | --- | --- |
| Identity and access | `app/Modules/Identity` | Registration, password authentication, expiring/revocable Sanctum tokens, multiple roles. |
| Customers | `app/Modules/Customers` | Owner-scoped addresses with coordinates and soft deletion. |
| Vendors | `app/Modules/Vendors` | Pending business applications, membership, approval-gated locations. |
| Product catalogue | `app/Modules/Catalog` | Categories, stock/prices, own-location editing, cached geographic discovery. |
| Cart and checkout | `app/Modules/Checkout` | One location per cart, versioning, server pricing, stock/coupon locks, scoped idempotency. |
| Orders and history | `app/Modules/Orders` | Item/address/price snapshots, authorization, state transitions, cancellation/rejection. |
| Payments | `app/Modules/Payments` | Snippe API adapter, signature verification, event deduplication, reconciliation, full refund workflows. |
| Delivery and riders | `app/Modules/Delivery` | Administrative rider approval/assignment and assignment-scoped delivery transitions. |
| Commissions and settlements | `app/Modules/Finance` | Basis-point commissions, balanced append-only ledger, cash commission debt, auditable settlement batches. |
| Notifications | `app/Modules/Notifications` | Durable outbox, queued in-app notifications, authenticated private Reverb updates. |
| Promotions | `app/Modules/Promotions` | Vendor-funded coupons, eligibility/usage limits and transactional redemptions. |
| Reviews and support | `app/Modules/Engagement` | One review per paid fulfilled order, customer/support-scoped tickets and messages. |
| Administration and audit | `app/Modules/Administration` | Role grants, approvals, refund/settlement attestations and immutable audit access. |

Shared Eloquent models live in `app/Models`, resource policies in `app/Policies`, checkout validation in `app/Http/Requests`, and explicit order serialization in `app/Http/Resources`. Models guard mass assignment. Financial services deliberately construct trusted fields. Snapshot/history triggers also protect against direct query updates. Domain boundaries use services and scoped query repositories; the external payment boundary has an injectable contract.

## Local setup

```sh
composer install
# Copy .env.example to .env and privately configure a development MySQL/Redis instance.
php artisan key:generate
php artisan migrate
php artisan marketplace:backfill-user-ids
php artisan db:seed
php artisan serve
```

The seeder creates categories only. Register accounts through the API. Use the trusted one-time administrator bootstrap in the operations guide, then approve vendor applications and create products. Never use migrations or seeders against an unidentified existing database.

The template defaults to MySQL/Redis, `APP_DEBUG=false`, private Reverb origins and disabled Snippe payouts. Credentials are blank. Mobile-money checkout returns 503 when required configuration is absent. There is no production payment-success simulator.

## Client contract

The API prefix is `/api/v1`. Send `Accept: application/json` and `Content-Type: application/json`. Obtain a token from `POST /auth/login` using `email`, `password` and `device_name`, then send `Authorization: Bearer <token>`. Registration assigns only `customer`; requesting a privileged role is rejected. Flutter should store tokens in platform secure storage. For Next.js, keep bearer tokens server-side behind a secure HttpOnly-cookie BFF; do not place bearer/provider secrets in public environment variables or local storage. Laravel's configured stateful Sanctum middleware retains CSRF protection for future first-party session integration; the implemented login contract issues bearer tokens.

Common response statuses: 401 unauthenticated, 403 insufficient role, 404 inaccessible/absent resource, 409 conflicting state/stock/idempotency, 422 invalid input, 429 rate limited, 503 required service unconfigured. JSON errors contain `message` and optionally field-level `errors`. Public identifiers are ULIDs, including `users.public_id`; numeric user IDs are internal. Money is integer TZS.

### Ordering flow

1. Discover kitchens with `GET /locations?latitude=-6.77&longitude=39.25&radius=5`, then `GET /locations/{location}/products`.
2. Save a delivery address through `/addresses` if needed. Use international mobile-money phone format such as `+255712345678`.
3. Set a cart product with `PUT /cart/items`, for example `{"product_id":"<ulid>","quantity":1,"instructions":"No chilli"}`. Quantity zero removes the item. A cart may contain one vendor location. Prices in cart responses are indicative until server checkout reprices and validates stock.
4. Read `GET /cart` and retain its `version`.
5. `POST /checkout` with a fresh `Idempotency-Key` header (8–100 letters/digits/underscores/hyphens) and a body such as:

```json
{
  "cart_version": 2,
  "fulfilment": "delivery",
  "payment_method": "mpesa",
  "phone": "+255712345678",
  "address_id": "<your-address-ulid>"
}
```

6. The response is an immutable order snapshot. For mobile money its initial order status is `awaiting_payment` and payment status `pending`. This is a stock reservation, **not a kitchen acceptance**. Await Snippe's USSD authorization and verified callback/status poll. Refetch `GET /orders/{id}` until `confirmed` + `verified`, or failure/cancellation. The reservation expires after 15 minutes; late verified payments enter an auditable refund workflow. The cart is consumed into the reservation; client retry logic must retain its returned order ID.
7. Retry a lost checkout response with the **same key and exact body**. It returns the existing reservation and does not charge or reserve again. A changed request with the same key returns 409. A new key means a new operation; never generate one automatically after an uncertain charge.
8. Vendors accept, prepare and mark ready through `PATCH /vendor/orders/{id}`. Delivery cannot enter preparation without verified mobile-money payment. Administrators assign paid ready deliveries, and only the assigned rider can record `picked_up` / `delivered`.

`pickup` removes the delivery fee and permits `cash`, `mpesa`, `tigo_pesa` (Snippe detects Mixx by Yas from the phone), or `airtel_money`. Cash pickup starts `confirmed` with payment `pending`. An authorized vendor must explicitly post `{"cash_received":true}` to `/vendor/orders/{id}/cash` before marking collection `completed`. The ledger records vendor cash commission as debt rather than a fictitious payable. This cash-pickup rule follows the new backend specification and differs from the adjacent Flutter demo's currently prepaid-only UI.

Delivery fees are configured as a base plus each rounded-up kilometre within the location's service radius. Commissions use the approved vendor's rate in basis points, after a vendor-funded discount; delivery revenue is separate. No client-supplied total, payment status or commission is accepted.

### Real-time and pagination

Authorize private Reverb subscriptions through `POST /api/broadcasting/auth` with a Sanctum token. Channels are `private-customers.{publicUserId}`, `private-vendors.{vendorId}` and `private-riders.{riderId}`. The event alias is `order.changed`; payloads contain `event_id`, `order_id`, order status and payment status, without phone/address/payment secrets. Deduplicate by event ID and refetch the authorized API resource. Delivery is at least once, not exactly once.

Lists use fixed page sizes (20 normally, 30 notifications/messages, 50 audit records), deterministic ordering and a bounded `page` parameter. Orders use Laravel resource `data/links/meta`; other paginated endpoints use Laravel paginator `data/current_page/per_page/total/links`. Addresses and categories are small collections. Relationships needed by list resources are eager-loaded; development rejects unexpected lazy loading.

## API documentation and checks

[OpenAPI 3.1 contract](docs/openapi.json) includes the API routes, request/response schemas, payment rules, signatures, pagination and authorization. Download it at `/api/v1/openapi.json`, import it into Postman/Insomnia, or generate Flutter/TypeScript clients using your chosen OpenAPI tooling. Source contracts live in `resources/openapi`; `composer openapi` rebuilds the document from registered routes and fails if a route has no metadata.

```sh
composer test
composer analyse
composer format
composer validate --no-check-publish
composer audit
composer openapi
```

Tests cover ownership and role isolation, expiring tokens, vendor approval, cart/stock/idempotency, delivery payment gates, cash collection, exact Snippe requests/signatures/status reconciliation, refunds and payout reversals, balanced ledger entries, settlement holds, immutable database history, geographic filtering, queued outbox recovery, coupons and support isolation. Additional real MySQL concurrency tests and PHP 8.4/8.5 CI configuration are included. See [release gates](docs/operations.md#verification-and-outstanding-release-gates) for what was and was not run locally.
# chips-api
