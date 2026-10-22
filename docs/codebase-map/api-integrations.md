# API & Integrations
> Last checked: 2026-10-20, commit 0e5316d

## What it does
The API & Integrations module manages machine-to-machine integrations, headless commerce endpoints, external e-commerce synchronizations, and inbound carrier/payment webhooks. It serves the `/api/v1/storefront/*` headless REST API powering the decoupled Next.js web application, providing cart quotations, server-side checkout, order tracking, and customer account portals. It exposes the `/api/v1/*` website API for external corporate sites to sync catalog SKUs, submit sales leads, and push orders using tenant Bearer API keys. Additionally, it handles bidirectional WooCommerce catalog imports and order status synchronization, Nuport order imports, outbound website change webhooks, and CSRF-exempt webhook endpoints for logistics couriers and online payment gateways.

## Where to start
- `routes/api.php` — Route definition file declaring external website integration endpoints and headless Next.js storefront endpoints.
- `app/Http/Middleware/ResolveCompanyFromApiKey.php` — Middleware authenticating incoming API requests against `CompanyApiKey` and binding `CompanyContext`.
- `app/Http/Middleware/EnsureNextjsSiteEnabled.php` — Route guard restricting Next.js storefront endpoints unless the tenant has enabled headless mode.
- `app/Http/Controllers/Api/V1/StorefrontCheckoutController.php` — Controller handling pricing quotations, payment finalization, and order creation for Next.js.
- `app/Services/WooCommerceOrderSyncService.php` — Bidirectional sync engine syncing order statuses between the ERP and remote WooCommerce stores.
- `app/Services/WebsiteWebhookDispatcher.php` — Outbound webhook dispatcher broadcasting catalog and inventory updates to external endpoints.
- `app/Filament/Pages/Integrations.php` — Unified administrative settings page for API keys, WooCommerce credentials, and webhooks.

## Files

### Models & Concerns
| Path | What it does |
| --- | --- |
| `app/Models/CompanyApiKey.php` | Bearer API credentials with hashed secrets, permissions, and tenant company association. |
| `app/Models/StorefrontSetting.php` | Configures whether the headless Next.js site API is enabled for a given tenant. |
| `app/Models/Concerns/NotifiesWebsiteOfStorefrontChanges.php` | Model trait dispatching outbound webhook notifications upon catalog changes. |

### Middleware
| Path | What it does |
| --- | --- |
| `app/Http/Middleware/ResolveCompanyFromApiKey.php` | Authenticates Bearer tokens from the `Authorization` header and sets `CompanyContext`. |
| `app/Http/Middleware/EnsureNextjsSiteEnabled.php` | Asserts that `storefront_settings.site_type = 'nextjs'` before permitting headless API calls. |
| `app/Http/Middleware/EnsureStorefrontAccountsEnabled.php` | Guards customer account authentication routes against disabled storefronts. |
| `app/Http/Middleware/AuthenticateStorefrontCustomer.php` | Validates customer session tokens for authenticated headless account routes. |
| `app/Http/Middleware/ForceJsonResponse.php` | Ensures all API error responses and validation exceptions return formatted JSON. |

### API Controllers (`routes/api.php`)
| Path | What it does |
| --- | --- |
| `app/Http/Controllers/Api/V1/ProductController.php` | External website API: lists products, views SKUs, and updates price/stock via PATCH. |
| `app/Http/Controllers/Api/V1/OrderController.php` | External website API: submits new customer orders and queries order status. |
| `app/Http/Controllers/Api/V1/LeadController.php` | External website API: ingests inquiries and leads from contact forms. |
| `app/Http/Controllers/Api/V1/StorefrontController.php` | Next.js API: serves site metadata, categories, products, slides, collections, and pages. |
| `app/Http/Controllers/Api/V1/StorefrontCheckoutController.php` | Next.js API: calculates checkout price quotes, places orders, and finalizes payments. |
| `app/Http/Controllers/Api/V1/StorefrontOrderTrackingController.php` | Next.js API: tracks parcel status using order number and phone or secure token. |
| `app/Http/Controllers/Api/V1/StorefrontAccountAuthController.php` | Next.js API: customer registration, login, OTP verification, and password resets. |
| `app/Http/Controllers/Api/V1/StorefrontAccountController.php` | Next.js API: customer profile management, past orders, reviews, and reorder requests. |

### Webhook Controllers (`routes/web.php`)
| Path | What it does |
| --- | --- |
| `app/Http/Controllers/WooCommerceWebhookController.php` | Ingests `order.created` and `order.updated` webhook payloads from WooCommerce. |
| `app/Http/Controllers/CourierWebhookController.php` | Ingests live tracking and delivery status webhooks from courier APIs. |
| `app/Http/Controllers/ZiniPayWebhookController.php` | Ingests Instant Payment Notifications (IPN) from the ZiniPay gateway. |
| `app/Http/Controllers/PayStationWebhookController.php` | Ingests transaction callback notifications from the PayStation gateway. |
| `app/Http/Controllers/MetaWebhookController.php` | Verifies and receives incoming webhooks from Meta Graph API. |
| `app/Http/Controllers/WhatsAppWebWebhookController.php` | Receives inbound message events from the local whatsapp-bridge service. |
| `app/Http/Controllers/MobileCrashReportController.php` | Receives crash analytics and diagnostic logs from the Flutter mobile app. |

### Services
| Path | What it does |
| --- | --- |
| `app/Services/WebsiteWebhookDispatcher.php` | Prepares and queues signed outbound webhook events to external consumer systems. |
| `app/Services/WooCommerceImportService.php` | Fetches products, variants, images, and categories from WooCommerce via REST. |
| `app/Services/WooCommerceOrderSyncService.php` | Pushes updated ERP delivery statuses back to remote WooCommerce instances. |
| `app/Services/NuportOrderImportService.php` | Imports sales orders and customer records from Nuport export formats. |

### Support
| Path | What it does |
| --- | --- |
| `app/Support/StorefrontApi/AccountPayload.php` | Formats customer profile and address data for JSON responses. |
| `app/Support/StorefrontApi/Media.php` | Resolves absolute CDN URLs for product images and promotional banners. |
| `app/Support/StorefrontApi/OrderSummary.php` | Serializes order summaries, payment states, and line item specs. |
| `app/Support/StorefrontApi/Phone.php` | Normalizes customer phone numbers to standard Bangladeshi formats. |
| `app/Support/StorefrontApi/RichText.php` | Sanitizes and renders HTML rich text content for headless CMS consumers. |
| `app/Support/StorefrontApi/Signing.php` | Signs order tracking tokens to permit customer access without passwords. |
| `app/Support/StorefrontApi/TrackingSummary.php` | Formats shipping stages and carrier consignment updates for the API. |
| `app/Support/WooCommerceDescription.php` | Parses and cleans HTML descriptions imported from WooCommerce products. |

### Jobs & Commands
| Path | What it does |
| --- | --- |
| `app/Jobs/DispatchWebsiteWebhookJob.php` | Queued HTTP job sending signed webhook payloads to registered subscriber URLs. |
| `app/Jobs/PushWooCommerceOrderStatusJob.php` | Background job calling WooCommerce REST API to sync order stage changes. |
| `app/Console/Commands/ImportWooCommerceProducts.php` | Artisan CLI command to run catalog imports from configured WooCommerce stores. |
| `app/Console/Commands/ImportNuportOrders.php` | Artisan CLI command to ingest batch order files from Nuport. |

### Filament Pages & Livewire Components
| Path | What it does |
| --- | --- |
| `app/Filament/Pages/Integrations.php` | Consolidated admin settings page for API keys, WooCommerce sync, and webhooks. |
| `app/Filament/Pages/Concerns/OpenedFromIntegrations.php` | Helper managing redirect back-links when opening sub-settings from Integrations. |
| `app/Livewire/WebsiteApiKeysTable.php` | Livewire table managing Bearer API keys, scopes, and revocation. |
| `app/Livewire/MetaEventLogTable.php` | Livewire table inspecting recently dispatched Meta Conversions API events. |
| `resources/views/filament/pages/integrations.blade.php` | Blade view for the integrations control center. |

### Tests
| Path | What it does |
| --- | --- |
| `tests/Feature/StorefrontApiTest.php` | Tests `/api/v1/storefront/` catalog, category, and page endpoints. |
| `tests/Feature/StorefrontApiCheckoutTest.php` | Tests `/api/v1/storefront/checkout` quote calculation and order placement. |
| `tests/Feature/StorefrontApiAccountTest.php` | Tests `/api/v1/storefront/account` authentication, OTPs, and order lookups. |
| `tests/Feature/WebsiteApiAuthTest.php` | Verifies Bearer key validation and company context resolution on `/api/v1/*`. |
| `tests/Feature/WebsiteApiProductTest.php` | Tests `/api/v1/products` listing, show, and PATCH price/stock updates. |
| `tests/Feature/WebsiteApiOrderTest.php` | Tests `/api/v1/orders` external submission and retrieval. |
| `tests/Feature/WebsiteApiLeadTest.php` | Tests `/api/v1/leads` inquiry submission. |
| `tests/Feature/WebsiteWebhookDispatchTest.php` | Tests HMAC signature and outbound delivery of website webhooks. |
| `tests/Feature/WooCommerceImportTest.php` | Tests product, category, and image importing from WooCommerce mock APIs. |
| `tests/Feature/WooCommerceOrderWebhookTest.php` | Tests inbound WooCommerce order webhooks creating ERP orders. |
| `tests/Feature/WooCommerceOrderStatusPushTest.php` | Tests pushing ERP order status transitions back to WooCommerce. |
| `tests/Feature/NuportOrderImportTest.php` | Tests batch file parsing and order generation from Nuport sources. |

## Routes and panels
- **Website API Routes** (`routes/api.php`, Bearer Key Auth):
  - `GET|HEAD api/v1/products` -> `App\Http\Controllers\Api\V1\ProductController@index`
  - `GET|HEAD api/v1/products/{sku}` -> `App\Http\Controllers\Api\V1\ProductController@show`
  - `PATCH api/v1/products/{sku}` -> `App\Http\Controllers\Api\V1\ProductController@update`
  - `POST api/v1/orders` -> `App\Http\Controllers\Api\V1\OrderController@store`
  - `GET|HEAD api/v1/orders/{order_number}` -> `App\Http\Controllers\Api\V1\OrderController@show`
  - `POST api/v1/leads` -> `App\Http\Controllers\Api\V1\LeadController@store`
- **Headless Next.js Storefront API Routes** (`routes/api.php`, Bearer Key Auth):
  - `GET|HEAD api/v1/storefront/site` -> `App\Http\Controllers\Api\V1\StorefrontController@site`
  - `GET|HEAD api/v1/storefront/categories` -> `App\Http\Controllers\Api\V1\StorefrontController@categories`
  - `GET|HEAD api/v1/storefront/collections` -> `App\Http\Controllers\Api\V1\StorefrontController@collections`
  - `GET|HEAD api/v1/storefront/products` -> `App\Http\Controllers\Api\V1\StorefrontController@products`
  - `GET|HEAD api/v1/storefront/products/{slug}` -> `App\Http\Controllers\Api\V1\StorefrontController@product`
  - `GET|HEAD api/v1/storefront/slides` -> `App\Http\Controllers\Api\V1\StorefrontController@slides`
  - `GET|HEAD api/v1/storefront/pages` -> `App\Http\Controllers\Api\V1\StorefrontController@pages`
  - `GET|HEAD api/v1/storefront/pages/{slug}` -> `App\Http\Controllers\Api\V1\StorefrontController@page`
  - `POST api/v1/storefront/checkout/quote` -> `App\Http\Controllers\Api\V1\StorefrontCheckoutController@quote`
  - `POST api/v1/storefront/checkout` -> `App\Http\Controllers\Api\V1\StorefrontCheckoutController@place`
  - `POST api/v1/storefront/checkout/payments/{payment}/finalize` -> `App\Http\Controllers\Api\V1\StorefrontCheckoutController@finalizePayment`
  - `GET|HEAD api/v1/storefront/orders/{order_number}` -> `App\Http\Controllers\Api\V1\StorefrontCheckoutController@order`
  - `POST api/v1/storefront/orders/track` -> `App\Http\Controllers\Api\V1\StorefrontOrderTrackingController@track`
  - `GET|HEAD api/v1/storefront/orders/{order_number}/tracking` -> `App\Http\Controllers\Api\V1\StorefrontOrderTrackingController@byToken`
  - `POST api/v1/storefront/account/register` -> `App\Http\Controllers\Api\V1\StorefrontAccountAuthController@register`
  - `POST api/v1/storefront/account/login` -> `App\Http\Controllers\Api\V1\StorefrontAccountAuthController@login`
  - `POST api/v1/storefront/account/logout` -> `App\Http\Controllers\Api\V1\StorefrontAccountAuthController@logout`
  - `POST api/v1/storefront/account/otp` -> `App\Http\Controllers\Api\V1\StorefrontAccountAuthController@sendOtp`
  - `POST api/v1/storefront/account/otp/verify` -> `App\Http\Controllers\Api\V1\StorefrontAccountAuthController@verifyOtp`
  - `POST api/v1/storefront/account/password/forgot` -> `App\Http\Controllers\Api\V1\StorefrontAccountAuthController@forgotPassword`
  - `POST api/v1/storefront/account/password/reset` -> `App\Http\Controllers\Api\V1\StorefrontAccountAuthController@resetPassword`
  - `GET|HEAD api/v1/storefront/account` -> `App\Http\Controllers\Api\V1\StorefrontAccountController@show`
  - `PATCH api/v1/storefront/account/profile` -> `App\Http\Controllers\Api\V1\StorefrontAccountController@updateProfile`
  - `PUT api/v1/storefront/account/password` -> `App\Http\Controllers\Api\V1\StorefrontAccountController@updatePassword`
  - `GET|HEAD api/v1/storefront/account/orders` -> `App\Http\Controllers\Api\V1\StorefrontAccountController@orders`
  - `GET|HEAD api/v1/storefront/account/orders/{order_number}` -> `App\Http\Controllers\Api\V1\StorefrontAccountController@order`
  - `POST api/v1/storefront/account/orders/{order_number}/reorder` -> `App\Http\Controllers\Api\V1\StorefrontAccountController@reorder`
  - `POST api/v1/storefront/account/orders/{order_number}/reviews` -> `App\Http\Controllers\Api\V1\StorefrontAccountController@storeReview`
  - `GET|HEAD api/v1/storefront/account/activity` -> `App\Http\Controllers\Api\V1\StorefrontAccountController@activity`
- **Inbound Webhook Routes** (`routes/web.php`, CSRF-exempt):
  - `POST webhooks/woocommerce/{company}` (name `woocommerce.webhook`) -> `App\Http\Controllers\WooCommerceWebhookController`
  - `POST webhooks/couriers/{provider}` (name `couriers.webhook`) -> `App\Http\Controllers\CourierWebhookController`
  - `POST webhooks/zinipay/{payment}` (name `zinipay.webhook`) -> `App\Http\Controllers\ZiniPayWebhookController`
  - `POST webhooks/paystation/{payment}` (name `paystation.webhook`) -> `App\Http\Controllers\PayStationWebhookController`
  - `GET|HEAD webhooks/meta` (name `webhooks.meta.verify`) -> `App\Http\Controllers\MetaWebhookController@verify`
  - `POST webhooks/meta` (name `webhooks.meta.handle`) -> `App\Http\Controllers\MetaWebhookController@handle`
  - `POST webhooks/whatsapp-web/{channel}` (name `webhooks.whatsapp-web.handle`) -> `App\Http\Controllers\WhatsAppWebWebhookController@handle`
  - `POST webhooks/whatsapp-web/{channel}/media` (name `webhooks.whatsapp-web.media`) -> `App\Http\Controllers\WhatsAppWebWebhookController@media`
  - `POST webhooks/mobile-crash-reports` (name `mobile-crash-reports.store`) -> `App\Http\Controllers\MobileCrashReportController@store`

## Data
- `company_api_keys`: External credentials table. Columns: `id`, `company_id`, `label`, `scope` (`website`, `sync`), `key_prefix`, `key_hash`, `last_used_at`, `revoked_at`, `created_by`, `created_at`, `updated_at`.
- `storefront_settings`: Next.js site flag and API configuration. Key integration columns: `id`, `company_id`, `site_type` (`blade`, `nextjs`), `nextjs_site_enabled`, `nextjs_site_domain`, `nextjs_site_domain_verified`, `website_api_credentials`, `woocommerce_base_url`, `woocommerce_credentials`, `online_payment_enabled`, `online_payment_gateway`, `payment_credentials`, `is_published`, `created_at`, `updated_at`.
- `orders`: Shared sales order table populated by external API submissions and WooCommerce webhooks. Key integration columns: `id`, `company_id`, `order_number`, `source`, `external_reference`, `status` (`pending`, `processing`, `completed`, `cancelled`), `delivery_status`, `total_amount`, `created_at`, `updated_at`.

## Rules that matter
- **Bearer Key Tenant Binding**: All `/api/v1/*` requests must provide an `Authorization: Bearer <key>` header. `ResolveCompanyFromApiKey` extracts the key, matches it against `company_api_keys.key_hash`, and binds that company into `CompanyContext`. If the key is invalid or inactive, the request is rejected with 401 Unauthorized. Enforced by `app/Http/Middleware/ResolveCompanyFromApiKey.php`.
- **Headless Mode Gate**: Next.js endpoints under `/api/v1/storefront/*` are strictly blocked with 403 Forbidden unless the tenant has configured a `nextjs` site record in `StorefrontSetting` via `EnsureNextjsSiteEnabled`. Enforced by `app/Http/Middleware/EnsureNextjsSiteEnabled.php`.
- **Server-to-Server Rate Limiting**: The Next.js storefront API uses rate limiting grouped by API key (`throttle:storefront-api`), rather than by client IP address, because all requests originate from the same Next.js server node. Enforced by `routes/api.php` and `app/Providers/AppServiceProvider.php`.
- **Webhook Loop Suppression**: When products or orders are updated from an external source (e.g. `PATCH /api/v1/products/{sku}` or WooCommerce sync), `$model->suppressWebsiteWebhook = true` or `Order::$suppressWooCommercePush = true` is set before saving to prevent echoing the update back out in an endless loop. Enforced by `app/Models/Product.php`, `app/Models/Order.php`, and `app/Services/WooCommerceOrderSyncService.php`.
- **CSRF Exclusion for Public Webhooks**: All carrier and payment webhook endpoints are explicitly listed in `bootstrap/app.php`'s `validateCsrfTokens(except: [...])` array so external servers can post payloads without session cookies. Enforced by `bootstrap/app.php`.
- **Legacy IMS Sync Retirement**: The legacy IMS sync API (`/api/v1/sync/*`) was permanently decommissioned on 2026-10-19; all legacy sync endpoints return 404. Documented in `routes/api.php`.

## Links to other modules
- **core-platform.md**: Bearer key authentication initializes `CompanyContext` to ensure tenant data isolation.
- **catalog-inventory.md**: Next.js API endpoints and WooCommerce sync read and update catalog records in `Product` and `Category`.
- **sales-orders-couriers.md**: API order submissions create records in `Order`, which flow into courier booking workflows.
- **storefront-blade.md**: Shares checkout pricing logic (`StorefrontCheckoutService`) and payment drivers (`PayStationClient`, `ZiniPayClient`).
- **crm-messaging.md**: Website leads posted to `/api/v1/leads` create CRM lead records.

## Related docs
- `docs/api/storefront-api.md` — API documentation for headless Next.js storefront developers.
- `docs/api/website-integration.md` — Integration guide for connecting external corporate websites.
- `docs/api/website-integration.openapi.yaml` — OpenAPI 3.0 contract definition for external website APIs.
- `docs/api/website-mapping-tasneem-noor.md` — Field mapping documentation for specific external sites.
- `docs/api/staging-setup.md` — Instructions for configuring sandbox and staging API keys.
- `09_DASHBOARD_WOOCOMMERCE_SECURITY_PLAN.md` — WooCommerce webhook authentication and error isolation architecture.
