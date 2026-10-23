# Storefront (Blade)
> Last checked: 2026-10-20, commit 0e5316d

## What it does
The Storefront (Blade) module powers the server-rendered customer-facing e-commerce storefront, reseller affiliate shops, promotional landing pages, and customer self-service accounts. It manages visual storefront design elements including home page section builders, multi-theme layouts (default, marketplace-pro, noor-solar), promotional banner slides, dynamic navigation menus, and modular footer blocks. It provides a multi-step checkout workflow with automated shipping fee calculation (inside/outside Dhaka), VAT handling, and risk-gated payment eligibility. The module integrates online payment gateways (ZiniPay, PayStation, and manual accounts), tracks Meta Conversions API (CAPI) server events, and automates abandoned cart recovery. Additionally, it handles customer complaints, order tracking, and dynamic multi-tenant SEO assets (sitemaps, robots.txt, schema markup).

## Where to start
- `app/Models/StorefrontSetting.php` — Central configuration model storing site type (`blade` vs `nextjs`), checkout policy, themes, payment credentials, and Meta Pixel IDs.
- `app/Services/StorefrontCheckoutService.php` — Primary checkout orchestration service coordinating order creation, payment gateway initiation, and stock reservation.
- `app/Services/StorefrontCart.php` — Session and database cart manager handling cart items, quantities, and discount calculations.
- `app/Support/StorefrontThemeRegistry.php` — Theme engine mapping tenant configurations to theme view overrides (`marketplace-pro`, `noor-solar`).
- `app/Services/PaymentGatewayResolver.php` — Factory resolving active payment drivers (ZiniPay, PayStation, manual transfer).
- `app/Services/StorefrontMetaTrackingService.php` — Dual-layer Meta tracking dispatcher sending browser Pixel and server-side Conversions API (CAPI) events.
- `app/Http/Controllers/Storefront/CheckoutController.php` — Customer checkout controller handling address entry, payment method selection, and order submission.
- `app/Filament/Resources/StorefrontSettings/StorefrontSettingResource.php` — Filament admin resource for configuring storefront design, themes, and policies.

## Files

### Models
| Path | What it does |
| --- | --- |
| `app/Models/StorefrontSetting.php` | Tenant storefront configuration for design, site type, payment keys, delivery fees, and Meta CAPI. |
| `app/Models/StorefrontSlide.php` | Promotional carousel slides with responsive desktop and mobile image banners. |
| `app/Models/StorefrontPage.php` | Custom CMS content pages (Terms, Privacy Policy, About Us, Refund Policy). |
| `app/Models/StorefrontPayment.php` | Online gateway transaction records tracking payment ID, gateway fee, and verification status. |
| `app/Models/StorefrontPaymentMethod.php` | Configurable payment methods (COD, bKash manual, Nagad manual, bank deposit). |
| `app/Models/StorefrontComplaint.php` | Customer after-sales complaints and inquiries submitted from order tracking. |
| `app/Models/StorefrontCartRecord.php` | Persisted customer shopping cart sessions used for abandoned cart analysis and recovery. |
| `app/Models/StorefrontCheckoutAttempt.php` | Audit log of submitted checkout payloads and risk evaluation outcomes. |
| `app/Models/StorefrontMetaEvent.php` | Outbox queue of Meta Conversions API events for delivery retry and audit. |
| `app/Models/StorefrontMetaAttribution.php` | Captures marketing campaign query parameters (utm_source, fbc, fbp) per order. |

### Services
| Path | What it does |
| --- | --- |
| `app/Services/StorefrontCheckoutService.php` | Validates cart state, creates orders, initiates payment gateways, and dispatches confirmation events. |
| `app/Services/StorefrontCheckoutPolicyService.php` | Enforces mandatory advance payments on high-risk customers or high-value orders. |
| `app/Services/StorefrontCart.php` | Session-based cart management with line item calculations and quantity rules. |
| `app/Services/StorefrontCatalogService.php` | Queries cached catalog products, categories, and inventory for storefront views. |
| `app/Services/StorefrontPaymentService.php` | Coordinates payment redirects, gateway returns, and transaction reconciliation. |
| `app/Services/StorefrontPaymentEligibilityService.php` | Determines allowed payment options (COD vs online advance) based on customer risk. |
| `app/Services/StorefrontDeliveryService.php` | Calculates delivery fees, courier transit days, and inside/outside Dhaka zones. |
| `app/Services/StorefrontDeliveryAreaResolver.php` | Resolves delivery zone based on customer district, city, or postal address. |
| `app/Services/StorefrontMetaTrackingService.php` | Formats and dispatches browser Pixel and server CAPI event payloads. |
| `app/Services/StorefrontMetaConversionsService.php` | Low-level client communicating with the Meta Graph API Conversions endpoint. |
| `app/Services/StorefrontMetaDispatchService.php` | Dispatches background jobs for asynchronous Meta event delivery. |
| `app/Services/StorefrontNotificationService.php` | Sends customer order confirmations and OTP verification codes via SMS. |
| `app/Services/StorefrontOrderPlacementService.php` | Low-level order persistence and sequential order number allocation. |
| `app/Services/StorefrontOrderTrackingService.php` | Fetches live order stages and courier tracking data for customer track pages. |
| `app/Services/StorefrontCustomerActivityService.php` | Logs customer storefront browsing, cart updates, and account logins. |
| `app/Services/StorefrontCustomerSession.php` | Manages storefront customer authentication sessions and OTP tokens. |
| `app/Services/StorefrontSiteConfigService.php` | Resolves tenant site logos, SEO metadata, and active theme settings. `branding()` is the site's light + dark logo pair (served in `/site`, and drawn by the reseller landing page header). |
| `app/Services/PaymentGatewayResolver.php` | Resolves gateway instances for ZiniPay, PayStation, or manual accounts. |
| `app/Services/ZiniPayClient.php` | Low-level API client for ZiniPay online payment gateway transactions. |
| `app/Services/PayStationClient.php` | Low-level API client for PayStation multi-channel gateway transactions. |

### Support
| Path | What it does |
| --- | --- |
| `app/Support/StorefrontCheckoutPage.php` | View model preparing data for the checkout blade template. |
| `app/Support/StorefrontCartLine.php` | Value object representing a single product line item in a customer's cart. |
| `app/Support/StorefrontCategoryIcons.php` | Maps category IDs to SVG category icon representations. |
| `app/Support/StorefrontErrorPages.php` | Renders branded tenant error views (404, 500) for storefront visitors. |
| `app/Support/StorefrontFooterStarter.php` | Generates default footer navigation and trust badge blocks. |
| `app/Support/StorefrontHomeStarter.php` | Generates default home page blocks (hero slider, categories, featured products). |
| `app/Support/StorefrontListingCache.php` | Manages Redis/file tag cache invalidation for storefront product listings. |
| `app/Support/StorefrontListingFilters.php` | Parses search, price range, and category query parameters for catalog listings. |
| `app/Support/StorefrontOrderSuccessPage.php` | Formats order summary data for the thank-you confirmation screen. |
| `app/Support/StorefrontProductPage.php` | Prepares product specs, gallery images, and reviews for product detail views. |
| `app/Support/StorefrontSchema.php` | Generates JSON-LD schema markup (Product, Organization, BreadcrumbList). |
| `app/Support/StorefrontSeo.php` | Injects OpenGraph, Twitter card, and canonical meta tags into HTML heads. |
| `app/Support/StorefrontSiteDesign.php` | Validates home page block schemas, colors, and typography settings. |
| `app/Support/StorefrontSitemap.php` | Generates dynamic XML sitemaps for products, categories, and content pages. |
| `app/Support/StorefrontThemeRegistry.php` | Resolves and registers available theme view namespaces. |
| `app/Support/StorefrontTrackPage.php` | Formats order tracking timelines for customer display. |
| `app/Support/StorefrontUrl.php` | Generates tenant-scoped storefront URLs for products, categories, and carts. |

### Filament Clusters, Resources & Pages
| Path | What it does |
| --- | --- |
| `app/Filament/Clusters/Storefront.php` | Navigation cluster grouping storefront settings, slides, pages, and payments. |
| `app/Filament/Resources/StorefrontSettings/StorefrontSettingResource.php` | Configuration resource for branding, themes, payment credentials, and policies. |
| `app/Filament/Resources/StorefrontSlides/StorefrontSlideResource.php` | Manages hero carousel banners and promotional slides. |
| `app/Filament/Resources/StorefrontPages/StorefrontPageResource.php` | CMS editor for custom content pages with rich text formatting. |
| `app/Filament/Resources/StorefrontPaymentMethods/StorefrontPaymentMethodResource.php` | Manages manual payment instructions (bKash/Nagad merchant accounts). |
| `app/Filament/Resources/StorefrontPayments/StorefrontPaymentResource.php` | Transaction log of all online and manual payment attempts. |
| `app/Filament/Resources/StorefrontComplaints/StorefrontComplaintResource.php` | Customer support complaint inbox with status resolution actions. |
| `app/Filament/Resources/StorefrontCartRecords/StorefrontCartRecordResource.php` | Abandoned cart monitor displaying cart values, items, and customer phone numbers. |
| `app/Filament/Resources/StorefrontCheckoutAttempts/StorefrontCheckoutAttemptResource.php` | Viewer for analyzed checkout submissions and fraud risk determinations. |
| `app/Filament/Resources/StorefrontMetaEvents/StorefrontMetaEventResource.php` | Monitor for Meta Conversions API event dispatch history and error statuses. |

### Controllers
| Path | What it does |
| --- | --- |
| `app/Http/Controllers/Storefront/HomeController.php` | Renders the storefront home page with dynamic slider and product sections. |
| `app/Http/Controllers/Storefront/ProductIndexController.php` | Handles catalog browsing, category filtering, search, and pagination. |
| `app/Http/Controllers/Storefront/ProductShowController.php` | Renders single product detail page with variant picker and reviews. |
| `app/Http/Controllers/Storefront/CartController.php` | Handles cart additions, quantity updates, removals, and drawer views. |
| `app/Http/Controllers/Storefront/CheckoutController.php` | Multi-step checkout form, shipping calculation, and order submission. |
| `app/Http/Controllers/Storefront/OrderTrackController.php` | Allows customers to track shipment progress using order number and phone. |
| `app/Http/Controllers/Storefront/ComplaintController.php` | Handles submission of customer support complaints against past orders. |
| `app/Http/Controllers/Storefront/ContactController.php` | Renders contact form and processes customer inquiries. |
| `app/Http/Controllers/Storefront/PageController.php` | Renders custom CMS content pages. |
| `app/Http/Controllers/Storefront/PreviewController.php` | Allows staff to preview draft home page designs before publishing. |
| `app/Http/Controllers/Storefront/OfferController.php` | Renders high-converting standalone promotional combo landing pages. |
| `app/Http/Controllers/Storefront/OfferCheckoutController.php` | One-page fast checkout for promotional landing page offers. |
| `app/Http/Controllers/Storefront/ProductReviewController.php` | Receives and stores customer product ratings and reviews. |
| `app/Http/Controllers/Storefront/AccountController.php` | Customer account dashboard displaying order history and profile data. |
| `app/Http/Controllers/Storefront/AccountAuthController.php` | Customer login, OTP registration, and password recovery. |
| `app/Http/Controllers/Storefront/AccountOrdersController.php` | Customer order list and detailed tracking in account portal. |
| `app/Http/Controllers/Storefront/AccountProfileController.php` | Updates customer delivery addresses and profile details. |
| `app/Http/Controllers/Storefront/MetaConsentController.php` | Saves visitor cookie consent preferences for Meta Pixel tracking. |
| `app/Http/Controllers/Storefront/SeoFilesController.php` | Dynamically streams `sitemap.xml` and `robots.txt` per company domain. |
| `app/Http/Controllers/ZiniPayWebhookController.php` | Receives IPN webhooks from ZiniPay and confirms order payments. |
| `app/Http/Controllers/PayStationWebhookController.php` | Receives payment callbacks from PayStation and validates payment status. |

### Jobs, Commands & Schedule
| Path | What it does |
| --- | --- |
| `app/Jobs/RetryStorefrontMetaEventJob.php` | Retries failed Meta Conversions API events with exponential backoff. |
| `app/Jobs/SendStorefrontMetaOrderStatusJob.php` | Dispatches server-side CAPI events when order status updates. |
| `app/Jobs/SendStorefrontMetaPurchaseJob.php` | Dispatches server-side `Purchase` CAPI events upon completed checkouts. |
| `app/Jobs/SendStorefrontMetaRecoveredJob.php` | Tracks when an abandoned cart customer returns and completes checkout. |
| `app/Console/Commands/SendAbandonedCartReminders.php` | Scheduled hourly job sending SMS recovery discount links to abandoned cart visitors. |
| `app/Console/Commands/RetryStorefrontMetaEvents.php` | Scheduled job running every 10 minutes to process queued Meta events. |
| `app/Console/Commands/SeedDefaultFooterBlocks.php` | Seeds standard footer blocks for newly created storefront sites. |

### Views
| Path | What it does |
| --- | --- |
| `resources/views/storefront/layout.blade.php` | Master Blade layout including header, footer, SEO tags, and Pixel scripts. |
| `resources/views/storefront/home.blade.php` | Default theme home page template with block components. |
| `resources/views/storefront/products/index.blade.php` | Product listing and search catalog layout. |
| `resources/views/storefront/products/show.blade.php` | Product detail page with gallery and variant selectors. |
| `resources/views/storefront/cart/show.blade.php` | Dedicated shopping cart page. |
| `resources/views/storefront/checkout/show.blade.php` | Single-page checkout interface with Alpine.js state management. |
| `resources/views/storefront/checkout/success.blade.php` | Order confirmation thank-you screen. |
| `resources/views/storefront/track/show.blade.php` | Parcel tracking timeline and status viewer. |
| `resources/views/storefront/complaints/show.blade.php` | Customer complaint submission form. |

### Migrations (name pattern only)
- Find a table's migrations by searching `database/migrations/` for the table name: several tables are created together in one migration, so the file name does not always match the table.
- `database/migrations/*create_storefront_settings_table.php`
- `database/migrations/*create_storefront_slides_table.php`
- `database/migrations/*create_storefront_pages_table.php`
- `database/migrations/*create_storefront_payment_methods_table.php`

### Tests
| Path | What it does |
| --- | --- |
| `tests/Feature/StorefrontFoundationTest.php` | Tests storefront home page rendering, navigation menus, and company scoping. |
| `tests/Feature/StorefrontThemeTest.php` | Tests multi-theme layout resolution (`marketplace-pro`, `noor-solar`). |
| `tests/Feature/StorefrontCheckoutPolicyTest.php` | Tests mandatory advance payment rules on high-risk checkouts. |
| `tests/Feature/StorefrontOnlineGatewayCheckoutTest.php` | Tests ZiniPay and PayStation checkout initiation and redirect flows. |
| `tests/Feature/StorefrontPaystationPaymentTest.php` | Tests PayStation webhook callbacks and payment verification. |
| `tests/Feature/StorefrontMetaTrackingTest.php` | Tests Pixel rendering and server CAPI event dispatching. |
| `tests/Feature/StorefrontResellerAndAbandonedCartTest.php` | Tests abandoned cart tracking and recovery SMS reminders. |
| `tests/Feature/StorefrontSeoTest.php` | Tests dynamic sitemap generation and Schema.org JSON-LD output. |
| `tests/Feature/StorefrontVatAndDeliveryTimeTest.php` | Tests delivery fee calculation and VAT rate additions. |

## Routes and panels
- **Panels**:
  - `admin` panel:
    - Storefront Cluster (`admin/storefront/*`): Storefront Settings, Slides, Pages, Payment Methods, Payments, Complaints, Cart Records (Abandoned Carts), Checkout Attempts, Meta Events.
- **Storefront HTTP Routes** (Public & Customer-facing):
  - `GET|HEAD /` (name `marketing.home`) -> `App\Http\Controllers\Storefront\HomeController`
  - `GET|HEAD products` (name `storefront.products.index`) -> `App\Http\Controllers\Storefront\ProductIndexController`
  - `GET|HEAD product/{slug}` (name `storefront.products.show`) -> `App\Http\Controllers\Storefront\ProductShowController`
  - `GET|HEAD cart` (name `storefront.cart.show`) -> `App\Http\Controllers\Storefront\CartController@show`
  - `POST cart/items/{slug}` (name `storefront.cart.add`) -> `App\Http\Controllers\Storefront\CartController@add`
  - `PATCH cart/items/{slug}` (name `storefront.cart.update`) -> `App\Http\Controllers\Storefront\CartController@update`
  - `DELETE cart/items/{slug}` (name `storefront.cart.remove`) -> `App\Http\Controllers\Storefront\CartController@remove`
  - `GET|HEAD cart/recover/{cart}/{token}` (name `storefront.cart.recover`) -> `App\Http\Controllers\Storefront\CartController@recover`
  - `GET|HEAD checkout` (name `storefront.checkout.show`) -> `App\Http\Controllers\Storefront\CheckoutController@show`
  - `POST checkout` (name `storefront.checkout.store`) -> `App\Http\Controllers\Storefront\CheckoutController@store`
  - `GET|HEAD checkout/success/{order}` (name `storefront.checkout.success`) -> `App\Http\Controllers\Storefront\CheckoutController@success`
  - `GET|HEAD track` (name `storefront.track.index`) -> `App\Http\Controllers\Storefront\OrderTrackController@index`
  - `GET|HEAD track/{orderNo}` (name `storefront.track.show`) -> `App\Http\Controllers\Storefront\OrderTrackController@show`
  - `GET|HEAD complaints` (name `storefront.complaints.show`) -> `App\Http\Controllers\Storefront\ComplaintController@show`
  - `POST complaints` (name `storefront.complaints.store`) -> `App\Http\Controllers\Storefront\ComplaintController@store`
  - `GET|HEAD pages/{slug}` (name `storefront.pages.show`) -> `App\Http\Controllers\Storefront\PageController@show`
  - `GET|HEAD sitemap.xml` (name `storefront.sitemap`) -> `App\Http\Controllers\Storefront\SeoFilesController@sitemap`
  - `GET|HEAD robots.txt` (name `storefront.robots`) -> `App\Http\Controllers\Storefront\SeoFilesController@robots`
  - `POST webhooks/zinipay/{payment}` (name `zinipay.webhook`) -> `App\Http\Controllers\ZiniPayWebhookController`
  - `POST webhooks/paystation/{payment}` (name `paystation.webhook`) -> `App\Http\Controllers\PayStationWebhookController`

## Data
- `storefront_settings`: Storefront master settings. Key columns: `id`, `company_id`, `site_type` (`blade`, `nextjs`), `storefront_theme`, `checkout_policy_mode`, `online_payment_enabled`, `online_payment_gateway`, `payment_credentials` (encrypted JSON), `delivery_charge_inside`, `delivery_charge_outside`, `delivery_first_kg_inside`, `delivery_first_kg_outside`, `delivery_additional_per_kg`, `meta_tracking_enabled`, `meta_browser_tracking_enabled`, `meta_capi_enabled`, `meta_pixel_id`, `meta_tracking_credentials`, `is_published`, `created_at`, `updated_at`.
- `storefront_slides`: Promotional slider banners. Key columns: `id`, `company_id`, `image`, `image_mobile`, `heading`, `subheading`, `cta_label`, `cta_url`, `sort_order`, `is_active`, `starts_at`, `ends_at`, `product_id`, `theme`, `template`, `fit_to_frame`, `eyebrow`, `created_at`, `updated_at`.
- `storefront_pages`: Custom CMS pages. Key columns: `id`, `company_id`, `title`, `slug`, `excerpt`, `content`, `meta_title`, `meta_description`, `is_published`, `sort_order`, `cover_image`, `created_at`, `updated_at`.
- `storefront_payment_methods`: Manual payment gateways. Key columns: `id`, `company_id`, `type`, `name`, `account_number`, `instructions`, `is_active`, `sort_order`, `reseller_only`, `created_at`, `updated_at`.
- `storefront_payments`: Transaction ledger for gateways. Key columns: `id`, `company_id`, `order_id`, `gateway`, `invoice_id`, `amount`, `status`, `payment_method`, `transaction_id`, `payload` (JSON), `purpose`, `checkout_data` (JSON), `storefront_payment_method_id`, `created_at`, `updated_at`.
- `storefront_complaints`: Customer feedback. Key columns: `id`, `company_id`, `order_id`, `complaint_number`, `order_reference`, `customer_name`, `phone`, `issue_type`, `details`, `status`, `internal_note`, `telegram_status`, `telegram_message_id`, `telegram_error`, `telegram_sent_at`, `created_at`, `updated_at`.
- `storefront_cart_records`: Abandoned cart logs. Key columns: `id`, `company_id`, `session_id`, `phone`, `customer_name`, `email`, `address`, `items` (JSON), `subtotal`, `status`, `reminded_at`, `last_reminder_channels`, `recovered_order_id`, `converted_at`, `recovered_at`, `reseller_customer_id`, `created_at`, `updated_at`.
- `storefront_checkout_attempts`: Log of checkout submissions and fraud evaluations. Key columns: `id`, `company_id`, `order_id`, `phone_hash`, `email_hash`, `ip_hash`, `phone_mask`, `email_mask`, `ip_mask`, `policy_mode`, `outcome`, `violations` (JSON), `cart_total`, `payment_method`, `courier_success_ratio`, `required_advance`, `advance_reasons` (JSON), `created_at`, `updated_at`.
- `storefront_meta_events`: Meta CAPI outbox. Key columns: `id`, `company_id`, `order_id`, `event_name`, `event_id`, `status`, `payload_summary` (JSON), `response_summary` (JSON), `error`, `sent_at`, `pixel_id`, `order_status_transition_id`, `storefront_cart_record_id`, `created_at`, `updated_at`.

## Rules that matter
- **Site Type Coexistence & Primary Site Resolution**: A company can maintain at most one `blade` site (the server-rendered storefront and reseller portal) and one `nextjs` site (the headless storefront). `StorefrontSetting::nextjsFor($companyId)` fetches the headless configuration; `bladeFor($companyId)` retrieves the Blade store; `primaryFor($companyId)` prefers the Next.js site if configured, falling back to Blade. Enforced by `app/Models/StorefrontSetting.php`.
- **Dynamic Multi-Theme Layout Fallback**: If a tenant configures a custom theme (e.g. `marketplace-pro` or `noor-solar`), views are resolved from `resources/views/storefront/themes/{theme}/*`. If a template is not defined in the custom theme directory, the view engine falls back to `resources/views/storefront/*`. Enforced by `app/Support/StorefrontThemeRegistry.php`.
- **Checkout Policy & Risk-Gated Payment**: When checkout policy is set to `enforce`, customers with high-risk profiles or customers without delivery history are restricted from selecting full COD; they are required to pay a fixed or percentage advance deposit through an online payment gateway before order confirmation. Enforced by `app/Services/StorefrontCheckoutPolicyService.php` and `app/Services/StorefrontPaymentEligibilityService.php`.
- **Encrypted Gateway Credentials**: Payment gateway credentials (ZiniPay API keys, PayStation secrets) are cast as `encrypted:array` on `StorefrontSetting` and are never exposed in plaintext in admin forms. Enforced by `app/Models/StorefrontSetting.php`.
- **Meta Conversions Server Deduplication**: Every browser pixel event and server-side CAPI event shares an identical `event_id` and timestamp, enabling Meta to deduplicate browser and server hits. Enforced by `app/Services/StorefrontMetaTrackingService.php`.
- **Automated Cart Abandonment Recovery**: Scheduled job `storefront:send-abandoned-cart-reminders` runs hourly to dispatch SMS recovery messages with pre-filled cart links to visitors who abandoned checkouts over 60 minutes prior. Enforced by `bootstrap/app.php` and `app/Console/Commands/SendAbandonedCartReminders.php`.

## Links to other modules
- **core-platform.md**: Multi-tenant isolation is enforced on all storefront models via `BelongsToCompany` and `SetCurrentCompany`.
- **catalog-inventory.md**: Storefront views read catalog products, variant prices, and live stock levels from `Product` and `Category`.
- **sales-orders-couriers.md**: Successful storefront checkouts create records in `Order`, trigger risk evaluation via `CustomerRiskService`, and dispatch courier booking feedback.
- **accounts-finance.md**: Online gateway payments recorded in `StorefrontPayment` generate accounting credit vouchers.
- **api-integrations.md**: Headless storefronts query the same product and checkout domain logic via `/api/v1/storefront/*` endpoints.
- **reseller.md**: The Blade storefront renders reseller-branded shops on dedicated subdomains or custom domains.

## Related docs
- `08_STOREFRONT_UX_FIXES_PLAN.md` — UX architecture, theme registration, and mobile drawer specifications.
- `STOREFRONT_REDESIGN_PLAN.md` — Theme system design and layout block schemas.
- `06_PAYSTATION_MULTI_COMPANY_GATEWAY_PLAN.md` — PayStation gateway integration architecture.
- `docs/storefront-go-live.md` — Checklist for going live with Blade storefronts.
