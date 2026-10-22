# Reseller Program & Portal
> Last checked: 2026-10-22, reseller landing page (portal host front page, website footer, Bangla switch, Anek Bangla) and the website banner button

## What it does
The Reseller Program & Portal module enables wholesale multi-vendor dropshipping, affiliate storefronts, commission earnings, and reseller account management. Approved resellers log into a dedicated Filament 5 portal (`/portal`) to browse wholesale catalogs, place customer dropship orders, track commission earnings, and request payout withdrawals to mobile banking or bank accounts. It allows resellers to launch their own branded affiliate stores on company subdomains (e.g. `shop.company.com`) or verified custom domains with automatic on-demand SSL issuance. The module supports customizable variation selling prices, security deposit ledgers, own-courier merchant account fulfillment, and self-service password recovery workflows.

## Where to start
- `app/Providers/Filament/ResellerPanelProvider.php` — Panel configuration for the `/portal` reseller portal (ID: `reseller`).
- `app/Models/Customer.php` — Customer model serving as the reseller authenticatable entity via `reseller_status`.
- `app/Services/ResellerOrderPlacementService.php` — Handles order placement from the portal, applying wholesale discounts and commission calculations.
- `app/Services/ResellerCommissionService.php` — Computes reseller profit margins and manages commission release upon order delivery.
- `app/Services/ResellerDepositService.php` — Manages reseller security deposits required for dropship fulfillment and courier risk.
- `app/Services/ResellerPayoutService.php` — Processes withdrawal payout requests to bank and mobile financial accounts.
- `app/Filament/Resources/Resellers/ResellerResource.php` — Administrative CRUD interface for reviewing, approving, and managing resellers.
- `app/Http/Controllers/TlsAskController.php` — On-demand TLS validation endpoint for Caddy SSL certificate issuance on custom reseller domains.

## Files

### Models
| Path | What it does |
| --- | --- |
| `app/Models/Customer.php` | Authenticatable reseller entity when `reseller_status = 'approved'`, storing balances and domains. |
| `app/Models/ResellerProduct.php` | Pivot table tracking custom reseller retail prices and storefront visibility overrides. |
| `app/Models/ResellerCommission.php` | Ledger entry for earned dropship commission margins linked to orders. |
| `app/Models/ResellerDepositEntry.php` | Security deposit transaction record tracking credits, deductions, and balance after. |
| `app/Models/ResellerPayoutRequest.php` | Withdrawal requests submitted by resellers to withdraw earned profits. |
| `app/Models/ResellerPayoutAccount.php` | Saved withdrawal payment methods (bKash, Nagad, Rocket, Bank Account) per reseller. |
| `app/Models/ProductVariant.php` | Serves as the underlying model for `ResellerVariationPriceResource` to set suggested prices. |
| `app/Models/ResellerLevel.php` | Company-made reseller level (set by hand) with an optional own return charge and minimum withdrawal; `Customer::resellerReturnCharge()` / `resellerMinWithdrawal()` pick the level's amount or the company's. |

### Services
| Path | What it does |
| --- | --- |
| `app/Services/ResellerCommissionService.php` | Calculates profits `(retail - wholesale)`, marks commissions earned, or voids on return. |
| `app/Services/ResellerDepositService.php` | Credits upfront deposits and deducts delivery charges for uncollected COD parcels. |
| `app/Services/ResellerPayoutService.php` | Validates withdrawal balances, creates payment vouchers, and completes disbursements. |
| `app/Services/ResellerPricing.php` | Resolves effective wholesale and retail prices for simple products and variants. |
| `app/Services/ResellerOrderPlacementService.php` | Validates reseller margins, customer addresses, and creates dropship orders. |
| `app/Services/ResellerOwnCourierService.php` | Manages orders shipped under the reseller's own courier merchant account. |
| `app/Services/ResellerPasswordResetService.php` | Generates secure password reset links and SMS OTP tokens for resellers. |
| `app/Services/ResellerDomainDnsChecker.php` | Queries DNS records to verify CNAME/A records for custom reseller domains. |
| `app/Services/ResellerSubdomainProbe.php` | Validates subdomain availability and checks for reserved system prefixes. |
| `app/Services/ResellerSalesReportService.php` | Per-reseller sales, profit (payable / pending delivery) and payouts for a period, for the admin sales report. |
| `app/Services/ResellerNotificationService.php` | SMS to a reseller when approved, when a profit becomes payable and when a withdrawal is paid; texts come from Reseller Settings and an empty text is never sent. |
| `app/Jobs/SendResellerNotificationJob.php` | Queued send of one reseller SMS (one try; restores the company context afterwards). |

### Filament Panel Provider & Portal Pages (`app/Filament/Reseller/`)
| Path | What it does |
| --- | --- |
| `app/Providers/Filament/ResellerPanelProvider.php` | Configures the `/portal` panel, authentication guard, and bottom navigation hooks. |
| `app/Filament/Reseller/Pages/Dashboard.php` | Reseller home screen showing balance cards, recent orders, and commission sparklines. |
| `app/Filament/Reseller/Pages/Products.php` | Wholesale catalog browser displaying trade prices, suggested prices, and photo downloads. |
| `app/Filament/Reseller/Pages/CreateOrder.php` | Portal dropship order placement form with live profit calculation. |
| `app/Filament/Reseller/Pages/EditOrder.php` | Allows resellers to update recipient details before orders enter processing. |
| `app/Filament/Reseller/Pages/Orders.php` | Reseller's order history table with live courier tracking links. |
| `app/Filament/Reseller/Pages/Earnings.php` | Detailed financial breakdown of pending, earned, and withdrawn commissions. |
| `app/Filament/Reseller/Pages/Withdrawals.php` | Reseller withdrawal request submission form and history. |
| `app/Filament/Reseller/Pages/PayoutAccounts.php` | Management interface for reseller bank and mobile wallet payout accounts. |
| `app/Filament/Reseller/Pages/MyStore.php` | Reseller affiliate store settings (subdomain, custom domain, store name, banner). |
| `app/Filament/Reseller/Pages/Profile.php` | Reseller personal profile, contact information, and business details. |
| `app/Filament/Reseller/Pages/FraudChecker.php` | Embedded courier fraud checker allowing resellers to verify customer phone numbers. |
| `app/Filament/Reseller/Pages/Auth/Login.php` | Custom authentication page for resellers. |
| `app/Filament/Reseller/Pages/Auth/Apply.php` | Public application form for new resellers to register. |
| `app/Filament/Reseller/Pages/Auth/ForgotPassword.php` | Password recovery request form. |
| `app/Filament/Reseller/Pages/Auth/ResetPasswordByLink.php` | Secure password reset form reached via email/SMS link. |
| `app/Filament/Reseller/Widgets/CommissionTrend.php` | Visual chart widget illustrating monthly commission growth. |
| `app/Filament/Reseller/Concerns/ForApprovedResellers.php` | Trait restricting portal page access to approved accounts. |

### Filament Admin Resources & Pages (`app/Filament/`)
| Path | What it does |
| --- | --- |
| `app/Filament/Clusters/Resellers.php` | Navigation cluster grouping reseller management in the admin panel. |
| `app/Filament/Resources/Resellers/ResellerResource.php` | Admin resource for approving applications, managing deposits, and setting margins. |
| `app/Filament/Resources/ResellerCommissions/ResellerCommissionResource.php` | Registry of all reseller commission transactions and settlement statuses. |
| `app/Filament/Resources/ResellerDeposits/ResellerDepositResource.php` | Admin viewer and manual adjustment ledger for security deposits. |
| `app/Filament/Resources/ResellerPayoutRequests/ResellerPayoutRequestResource.php` | Workflow queue for approving, processing, or rejecting withdrawal requests. |
| `app/Filament/Resources/ResellerVariationPrices/ResellerVariationPriceResource.php` | Batch tool for setting suggested reseller retail prices on variants. |
| `app/Filament/Pages/ResellerSettings.php` | Configures reseller program defaults, commission payout minimums, terms and the SMS-to-resellers texts. |
| `app/Filament/Pages/ResellerSalesReport.php` | Read-only report, one row per reseller for a chosen period (view `filament/pages/reseller-sales-report`). |
| `app/Filament/Resources/ResellerLevels/ResellerLevelResource.php` | Reseller levels list with pop-up forms; the level is set on the reseller's edit page or in bulk on the reseller list. |

### Controllers & Middleware
| Path | What it does |
| --- | --- |
| `app/Http/Middleware/ResolveCompanyFromResellerPortalDomain.php` | Identifies tenant company from incoming `reseller.<domain>` hosts. |
| `app/Http/Middleware/ResolveResellerFromSlug.php` | Identifies reseller from subdomain or URL slug during storefront visits. |
| `app/Http/Middleware/RestrictPortalHosts.php` | Enforces domain isolation between portal, admin, and storefront routes; lets the reseller host's `/` through (the landing page) while `Company::resellerLandingEnabled()` and remembers the portal company on the request. |
| `app/Http/Middleware/SetResellerPortalLocale.php` | The English / Bangla switch of the portal's public pages: `?lang=` sets cookie `reseller_portal_locale`; runs on portal hosts only (web group, panel stack, Livewire persistent middleware). |
| `app/Http/Controllers/TlsAskController.php` | Authorizes on-demand TLS SSL certificate issuance for reseller domains. |
| `app/Http/Controllers/Reseller/ResellerGuideController.php` | The public reseller landing page (portal host front page via `HomeController`, and `/portal/guide`): program explanation, questions and answers, rules, apply / sign-in buttons, the Next.js site's footer. |
| `app/Http/Controllers/Reseller/ResellerOnlinePaymentReturnController.php` | Handles gateway callbacks when resellers top up security deposits online. |
| `app/Http/Controllers/Reseller/ResellerProductPhotosController.php` | Generates zip archives of high-resolution product photos for resellers. |
| `app/Http/Controllers/Storefront/ResellerController.php` | Public reseller application processing on the Blade storefront. |
| `app/Http/Controllers/Storefront/ResellerStoreController.php` | Renders affiliate storefronts customized with the reseller's branding. |

### Views
| Path | What it does |
| --- | --- |
| `resources/views/filament/reseller/pages/dashboard.blade.php` | Reseller portal dashboard view with metrics and announcements. |
| `resources/views/filament/reseller/pages/products.blade.php` | Reseller wholesale product catalog view. |
| `resources/views/filament/reseller/pages/create-order.blade.php` | Reseller dropship order creation view. |
| `resources/views/filament/reseller/pages/my-store.blade.php` | Reseller custom store configuration view. |
| `resources/views/filament/reseller/pages/earnings.blade.php` | Reseller earnings and commission breakdown view. |
| `resources/views/filament/pages/reseller-settings.blade.php` | Admin reseller configuration view. |
| `resources/views/reseller/guide.blade.php` | The landing page, neo-brutalist (styles in `resources/css/reseller-landing.css`, font Anek Bangla in `resources/fonts/anek-bangla/`). |
| `resources/views/reseller/partials/footer.blade.php` | The Next.js website's Footer Builder footer on the landing page (data from `StorefrontSiteConfigService::footer()`). |
| `resources/views/reseller/partials/language-switch.blade.php`, `resources/views/filament/reseller/language-switch.blade.php` | The English / Bangla switch on the landing page and on the sign-in / application pages. |

### Migrations (name pattern only)
- Find a table's migrations by searching `database/migrations/` for the table name: several tables are created together in one migration, so the file name does not always match the table.
- `database/migrations/*create_reseller_commissions_table.php`
- `database/migrations/*create_reseller_deposit_entries_table.php`
- `database/migrations/*create_reseller_payout_requests_table.php`
- `database/migrations/*create_reseller_payout_accounts_table.php`
- `database/migrations/*create_reseller_products_table.php`
- `database/migrations/*add_reseller_*_to_customers_table.php`

### Tests
| Path | What it does |
| --- | --- |
| `tests/Feature/ResellerPortalTest.php` | Tests portal login, dashboard access, and status gates. |
| `tests/Feature/ResellerCommissionTest.php` | Tests commission margin math, pending states, and release upon delivery. |
| `tests/Feature/ResellerCustomDomainTest.php` | Tests domain mapping and `/tls-ask` endpoint verification. |
| `tests/Feature/ResellerDepositAdminTest.php` | Tests security deposit ledger adjustments and balance tracking. |
| `tests/Feature/ResellerOwnCourierOrderTest.php` | Tests order fulfillment using reseller-owned courier merchant accounts. |
| `tests/Feature/ResellerPortalCreateOrderTest.php` | Tests dropship order submission from the reseller portal. |
| `tests/Feature/ResellerPortalPasswordResetTest.php` | Tests password reset workflows and token verification. |
| `tests/Feature/ResellerStorefrontTest.php` | Tests branded affiliate store rendering on custom subdomains. |
| `tests/Feature/ResellerVariationPricingTest.php` | Tests variant price overrides and suggested price fallbacks. |
| `tests/Feature/ResellerLevelsTest.php` | Tests levels, the level's return charge and minimum withdrawal, and company isolation. |
| `tests/Feature/ResellerNotificationsTest.php` | Tests the three reseller SMS, empty texts, opt-outs and no repeats. |
| `tests/Feature/ResellerSalesReportTest.php` | Tests the sales report figures and access. |
| `tests/Feature/ResellerLandingPageTest.php` | Tests the landing page at the portal host, its footer from the Next.js site, the questions and answers, the Bangla switch, the banner picture setting and the shipped font. |
| `tests/Feature/ResellerGuidePageTest.php` | Tests the guide content, money figures and the switch that turns the landing page off. |

## Routes and panels
- **Panels**:
  - `reseller` portal panel: URL prefix `portal`, provider: `App\Providers\Filament\ResellerPanelProvider`, panel ID `reseller`.
  - `admin` panel: Resellers Cluster (`admin/resellers/*`).
- **HTTP Routes**:
  - `GET|HEAD internal/tls-ask` (name `internal.tls-ask`) -> `App\Http\Controllers\TlsAskController`
  - `GET|HEAD /` on a reseller portal host (name `marketing.home`, `HomeController` hands over) and `GET|HEAD portal/guide` (name `reseller.portal.guide`) -> `App\Http\Controllers\Reseller\ResellerGuideController`
  - `GET|HEAD portal/products/{product}/photos` (name `reseller.portal.products.photos`) -> `App\Http\Controllers\Reseller\ResellerProductPhotosController`
  - `GET|HEAD portal/orders/payment/{payment}/return` (name `reseller.portal.orders.payment-return`) -> `App\Http\Controllers\Reseller\ResellerOnlinePaymentReturnController`
  - `GET|HEAD portal/register` (name `filament.reseller.auth.register`) -> `App\Filament\Reseller\Pages\Auth\Apply`
  - `GET|HEAD portal/login` (name `filament.reseller.auth.login`) -> `App\Filament\Reseller\Pages\Auth\Login`
  - `GET|HEAD portal/password-reset/request` (name `filament.reseller.auth.password-reset.request`) -> `App\Filament\Reseller\Pages\Auth\ForgotPassword`
  - `GET|HEAD portal/password-reset/reset` (name `filament.reseller.auth.password-reset.reset`) -> `App\Filament\Reseller\Pages\Auth\ResetPasswordByLink`
  - `GET|HEAD reseller` (name `storefront.reseller.show`) -> `App\Http\Controllers\Storefront\ResellerController@show`
  - `POST reseller` (name `storefront.reseller.store`) -> `App\Http\Controllers\Storefront\ResellerController@store`

## Data
- `customers`: Serves as reseller entity. Key reseller columns: `id`, `company_id`, `name`, `phone`, `email`, `reseller_status`, `business_name`, `reseller_note`, `reseller_slug`, `reseller_store_name`, `reseller_subdomain`, `reseller_custom_domain`, `reseller_custom_domain_verified_at`, `reseller_logo`, `reseller_logo_dark`, `reseller_subdomain_changed_at`, `reseller_subdomain_live_at`, `created_at`, `updated_at`.
- `reseller_commissions`: Commission transactions. Key columns: `id`, `company_id`, `order_id`, `customer_id`, `sales_amount`, `base_amount`, `amount`, `status`, `payable_at`, `created_at`, `updated_at`.
- `reseller_deposit_entries`: Security deposit ledger. Key columns: `id`, `company_id`, `customer_id`, `order_id`, `storefront_payment_id`, `type`, `amount`, `note`, `created_by`, `created_at`, `updated_at`.
- `reseller_payout_requests`: Withdrawal requests. Key columns: `id`, `company_id`, `customer_id`, `amount`, `method`, `account_details` (JSON), `status`, `payment_reference`, `admin_note`, `processed_by`, `voucher_id`, `approved_at`, `paid_at`, `created_at`, `updated_at`.
- `reseller_payout_accounts`: Beneficiary payout details. Key columns: `id`, `company_id`, `customer_id`, `method`, `account_details` (JSON), `label`, `is_default`, `created_at`, `updated_at`.
- `reseller_levels`: Company-made levels. Key columns: `id`, `company_id`, `name` (unique per company), `sort_order`, `return_charge` and `min_withdrawal` (nullable = company amount), timestamps; `customers.reseller_level_id` (nullable, `nullOnDelete`).
- `storefront_settings.reseller_banner_image` (nullable path): the picture of the website footer's "Become a reseller" button; empty means the standard banner `public/images/reseller-program-banner.png`.
- `storefront_settings` reseller SMS texts: `reseller_message_approved`, `reseller_message_profit_payable`, `reseller_message_payout_paid` (nullable).
- `reseller_products`: Per-reseller pricing overrides. Key columns: `id`, `customer_id`, `product_id`, `is_active`, `price`, `variant_prices` (JSON), `created_at`, `updated_at`.

## Rules that matter
- **Approved Status Requirement**: A customer account must have `reseller_status = 'approved'` to log into the `/portal` panel or sell via an affiliate store. Pending or suspended accounts are redirected to informational holding screens. Enforced by `app/Filament/Reseller/Concerns/ForApprovedResellers.php` and `app/Models/Customer.php`.
- **Portal Domain Isolation**: The reseller portal is served on `reseller.<company-domain>` or a designated custom portal domain. `RestrictPortalHosts` middleware enforces that `/portal` paths return 404 on normal storefront/admin hosts, and non-portal requests return 404 on portal hosts. Enforced by `app/Http/Middleware/RestrictPortalHosts.php`.
- **Commission Settlement Lifecycle**: Commission margin equals `(order_sale_price - wholesale_price)`. It is created in `pending` state upon order placement, becomes `earned` only after the courier confirms parcel delivery, and is immediately voided if the parcel is returned or cancelled. Enforced by `app/Services/ResellerCommissionService.php`.
- **Variation Pricing Precedence**: Products with variants require selling prices. `ResellerVariationPriceResource` allows admins to set suggested selling prices on `product_variants`. If no suggested price is defined by admin, the variation is hidden from reseller storefronts until the reseller sets an explicit custom price. Enforced by `app/Filament/Resources/ResellerVariationPrices/ResellerVariationPriceResource.php` and `app/Services/ResellerPricing.php`.
- **Own-Courier Operations**: If a reseller enables `reseller_uses_own_courier = true`, dropship orders are booked under the reseller's carrier credentials; the company does not collect COD from the courier, and courier delivery fee risk is borne by the reseller. Enforced by `app/Services/ResellerOwnCourierService.php`.
- **Automated SSL Certificate Authorization**: When a reseller connects a custom domain or subdomain, Caddy's on-demand TLS queries `/internal/tls-ask`. The endpoint verifies the domain belongs to an approved reseller before returning HTTP 200, preventing unauthorized domain SSL issuance. Enforced by `app/Http/Controllers/TlsAskController.php`.

## Links to other modules
- **core-platform.md**: Panel configuration in `ResellerPanelProvider` hooks into `SetCurrentCompany` and tenant context services.
- **catalog-inventory.md**: Resellers view wholesale pricing and live stock levels from `Product` and `ProductVariant`.
- **sales-orders-couriers.md**: Reseller dropship orders create standard records in `Order`, with special reseller profit attributes and courier bookings.
- **accounts-finance.md**: Approved payout withdrawals generate accounting debit vouchers through `VoucherService` and `ResellerPayoutService`.
- **storefront-blade.md**: The Blade storefront renders reseller-branded shops when accessed via custom reseller subdomains.

## Related docs
- `13_RESELLER_PORTAL_PLAN.md` — Original architecture specification for the standalone reseller portal.
- `docs/reseller-subdomain-setup.md` — DNS configuration guide for subdomains and custom domains.
