# School Supplies & Printing SaaS API

Laravel 12 REST API for the Papertrail Next.js frontend. The local API uses Sanctum bearer tokens; all prices are stored and returned in PKR. No payment provider is connected.

## Requirements and local setup (Windows PowerShell)

PHP 8.2+, Composer 2+, and the local MySQL instance configured in `backend/.env`. Keep that existing file and its credentials; the application was migrated and seeded against the configured local database `copies`. Do not copy `.env.example` over an existing `.env`.

```powershell
composer install
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8001
```

The development seeder exits in production and creates sample sellers/customers, including `seller@example.test`, `customer@example.test`, `pending-shop@example.test`, and `suspended-shop@example.test`. Their development-only password is `ChangeThisDemoPassword123!`; never use these accounts outside a local development database. The old seeded `admin@example.test` account is deactivated by the seeder. Provision a Super Admin interactively with `php artisan admin:provision`; the password is entered without echo and is never stored in source or frontend configuration. No default Super Admin account is created.

Tests run against an in-memory SQLite database configured in `phpunit.xml` and do not read or modify the configured MySQL database:

```powershell
php artisan test
```

## API conventions

Base URL: `http://127.0.0.1:8001/api/v1`. Send and receive JSON; protected endpoints use `Authorization: Bearer <token>`. Sanctum personal access tokens expire after seven days by default (`SANCTUM_TOKEN_EXPIRATION`). The Next.js client stores the token in tab-scoped `sessionStorage`; it is cleared on logout and is not placed in URLs. Paginated list responses use `data` and `meta`. Validation failures use Laravel's JSON `422` response. Public catalog responses expose only published products belonging to active vendors. Currency is `PKR`.

### Authentication

| Method | Path | Access |
| --- | --- | --- |
| POST | `/auth/register` | Public; customer registration |
| POST | `/auth/vendor/register` | Public, rate limited; creates a vendor user and pending store |
| POST | `/auth/login` | Public, rate limited |
| POST | `/auth/password/forgot` | Public, rate limited |
| POST | `/auth/password/reset` | Public, rate limited |
| POST | `/school/register` | Active vendor hostname only; creates a school profile bound to that vendor |
| POST | `/school/login` | Active vendor hostname only; credentials must belong to that vendor |
| GET | `/auth/me` | Authenticated |
| POST | `/auth/logout` | Authenticated |
| POST | `/vendors/apply` | Authenticated; creates pending vendor application |
| GET/POST | `/addresses` | Authenticated customer's saved shipping addresses |
| PUT/DELETE | `/addresses/{address}` | Authenticated; address owner only |

Registration expects `name`, `email`, `password`, and `password_confirmation`. Login and registration return a Sanctum bearer token. Forgot-password messages are sent through Laravel's configured mailer; local `.env.example` logs mail rather than sending it.

### Marketplace and storefront

| Method | Path | Description |
| --- | --- | --- |
| GET | `/vendors` | Active vendors, paginated |
| GET | `/vendors/{slug}` | Active storefront and settings |
| GET | `/vendors/resolve?slug=...` or `?host=...` | Storefront lookup; host mappings must be verified and active |
| GET | `/categories` | Active global categories |
| GET | `/products` | Search/filter/sort/paginate published products |
| GET | `/products/{id}` | Published product details |
| GET | `/printing/products` | Published printing products and specifications |

`GET /products` supports `q`, `category`, `vendor`, `min_price`, `max_price`, `available=true`, `sort=created_at|price|name`, `direction=asc|desc`, `page`, and `per_page` (maximum 100).

### Customer cart, checkout, orders, printing

| Method | Path | Access |
| --- | --- | --- |
| GET/POST | `/cart`, `/cart/items` | Authenticated |
| PATCH/DELETE | `/cart/items/{item}` | Authenticated; item scoped to own cart |
| POST | `/checkout` | Authenticated, rate limited; server prices and stock are authoritative |
| GET | `/orders` | Authenticated customer's orders |
| GET | `/orders/{order}` | Authenticated owner only |
| POST | `/printing/quotes` | Public or authenticated, rate limited; optional private artwork file |
| GET | `/printing/quotes/{quotation}` | Authenticated quote owner |

Checkout expects product IDs, quantities, and a shipping address. Client totals are ignored. Product rows are locked in a database transaction, price snapshots are stored on order items, and inventory is decremented. Orders start `pending` with `payment_status=unpaid`; no payment is collected.

Quote artwork accepts PDF, PNG, and JPEG up to 10 MB and is stored on the non-public `private` disk. The response returns artwork metadata but never its private path.

### Vendor-specific school accounts

School routes are under `/school` and resolve the tenant from the validated `X-Store-Host` header (sent by the browser API client) or request hostname. The hostname must map to a verified, active `store_domains` row whose vendor status is `active`. The header cannot select a vendor by ID; each authenticated school profile must also match that resolved vendor. Registration derives `vendor_id` on the server and ignores any submitted vendor ID. School catalog responses expose only that vendor's published supply and printing products. School checkout accepts only products from that same vendor and creates unpaid orders; school order history and vendor school/order APIs are scoped to their owner/vendor. Vendor managers can list and disable their own schools.

| Method | Path | Access |
| --- | --- | --- |
| POST | `/school/register` | Active vendor host; rate limited |
| POST | `/school/login` | Active vendor host; rate limited |
| GET/PUT | `/school/me`, `/school/profile` | School token on its own vendor host |
| POST | `/school/logout` | School token on its own vendor host |
| GET | `/school/products?type=supply|print` | School token on its own vendor host |
| POST | `/school/orders` | School token; same-vendor published products only |
| GET | `/school/orders`, `/school/orders/{order}` | School owner and matching vendor only |
| GET | `/vendor/{vendor}/schools` | Vendor owner/manager; own vendor only |
| PATCH | `/vendor/{vendor}/schools/{school}` | Vendor owner/manager; disable or enable own school's access |

Apply the school profile migration with `php artisan migrate`. Existing local `.env` values are retained. For local testing, assign an already approved and active vendor hostname (for example `school-shop.localhost`) in `store_domains` with `is_verified=1`, `provisioning_status=active`, and `vendor.status=active`. Point wildcard local DNS/hosts resolution to the same Next.js server; no separate frontend deployment is used. `frontend/.env.local` must use the Laravel API URL and `NEXT_PUBLIC_PLATFORM_DOMAIN=localhost`; CORS must permit the tenant origin (the example pattern includes `*.localhost:3000`).

### Vendor workspace

All paths below require Sanctum and a membership in the `{vendor}` slug. Owners/managers may manage products and settings; staff can view catalog/inventory and update vendor order statuses. Vendor-owned product lookups are scoped and return 404 for another store's records.

`/vendor/{vendor}/dashboard`, `/products` (GET/POST), `/products/{product}` (PUT/DELETE), `/products/{product}/images` (POST), `/products/{product}/inventory` (PUT), `/orders` (GET), `/orders/{order}` (PATCH), `/settings` (GET/PUT), `/domains` (GET/POST), `/domains/{domain}` (DELETE), `/members` (GET/POST), `/members/{membership}` (DELETE), `/printing/quotes` (GET), and `/printing/quotes/{quotation}` (PATCH). Only owners/managers can manage members and settings. New domain mappings require platform admin verification before resolution.

### Platform administration

All admin paths require Sanctum and `users.role=platform_admin`:

`GET /admin/overview`, `/vendors`, `/vendor-requests?status=pending|active|rejected|suspended|all`, `/vendor-requests/{vendor}`, `/users`, `/products`, `/categories`, `/orders`, `/subscriptions`, `/domains`; `POST /admin/vendor-requests/{vendor}/approve|reject`; `PATCH /admin/vendors/{vendor}/suspend|reactivate`; `POST /admin/domains/{domain}/activate`; `PATCH /admin/users/{user}` and `/admin/domains/{domain}`; `POST /admin/categories`.

Vendor status and rejection reason are available at `GET /vendor/{vendor}/application`; rejected owners may securely resubmit at `POST /vendor/{vendor}/reapply`. Notifications are available at `GET /notifications` and may be marked read with `PATCH /notifications/{notification}`. Local mail defaults to `log`, so review notices are stored in the authenticated user's in-app notifications; configure a delivery mailer in the backend environment to enable email notification attempts.

**Storefront routing** is configurable with `STOREFRONT_ROUTING_MODE`. `path` (default) publishes a store at `{STOREFRONT_BASE_URL}/vendor/{slug}`: the frontend sends the slug as `X-Store-Slug`, the API requires an active vendor with that slug, and approval needs no `PLATFORM_DOMAIN` and no DNS. `domain` publishes `https://{slug}.PLATFORM_DOMAIN` or a verified custom domain via `X-Store-Host`; approval then requires `PLATFORM_DOMAIN` (503 otherwise) and creates a `manual_pending` hostname mapping that a Super Admin activates after Vercel/DNS setup. In path mode a configured `PLATFORM_DOMAIN` still prepares that pending mapping, and verified host mappings keep resolving, so switching modes later needs no data migration. Domain mode ignores `X-Store-Slug`. Set the frontend's `NEXT_PUBLIC_STOREFRONT_MODE` to the same value. See [`../docs/vercel-multitenant-domains.md`](../docs/vercel-multitenant-domains.md) for domain mode.

## Database model

Tables cover users, vendors, vendor memberships, store settings and verified domains, hierarchical global/vendor categories, products, product image metadata, inventory, customer addresses, carts and cart items, orders and vendor-scoped order items, print specifications, quotation requests, private artwork metadata, vendor subscriptions, Sanctum tokens, and Laravel cache/queue tables. Prices use `DECIMAL(12,2)` plus an explicit `PKR` currency code. Product and vendor records use soft deletes where appropriate; order items preserve name and price snapshots.

## Frontend integration

In `frontend/.env.local`, set `NEXT_PUBLIC_API_URL=http://127.0.0.1:8001/api`; the frontend appends the `/v1` API version. `FRONTEND_URL=http://localhost:3000` allows the local Next.js origin. Public catalog, categories, storefronts, printing products, and quote requests are connected. Customer and vendor registration/login, password recovery, customer profile/orders, unpaid order placement, and vendor/admin workspaces use the real API. The cart remains tab-local in the frontend and is submitted to the API at checkout. No payment processor is connected.

`FRONTEND_URL` controls exact allowed CORS origins (comma-separated); `FRONTEND_URL_PATTERN` enables a narrowly scoped wildcard for tenant storefront origins (local development defaults to `http://*.localhost:3000`). Authentication uses bearer tokens, not credentialed cross-origin cookies, so the frontend does not need Sanctum CSRF cookies. Do not place credentials in `NEXT_PUBLIC_*` variables.

## AWS preparation

Configure production `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, MySQL RDS host/database/user/password, `FRONTEND_URL`, and strong generated `APP_KEY`. Provision S3 and set `FILESYSTEM_DISK=s3` plus the `AWS_*` environment variables. The AWS Flysystem adapter is included; no AWS credentials or resources are created by this project. Keep artwork private and serve it only through the authorized customer/vendor download endpoints or short-lived signed URLs. Use HTTPS, a process manager/web server, queue workers for mail and image processing, cache configuration/routes, configure centralized logs, automate encrypted RDS backups, and deploy migrations as a reviewed release step.
