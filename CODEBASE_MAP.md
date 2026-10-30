# Business-Dashboard Codebase Map
> Last checked: 2026-10-30, branch ccr-91b5e504-q154a8

Business-Dashboard is a multi-tenant enterprise resource planning (ERP), e-commerce, and investment management system built on Laravel 12 and Filament 5. It manages business operations for multiple independent companies across wholesale inventory, retail order fulfillment, Bangladeshi courier logistics (Steadfast, Pathao, RedX, eCourier), double-entry financial accounting, omnichannel messaging (WhatsApp, Messenger, SMS), Islamic Mudarabah investment syndicates (IMS v2), reseller dropshipping portals, headless Next.js and server-rendered Blade storefronts, and generative AI marketing suites.

## How to use this map
1. **Read this file first** to identify which module encompasses the feature or bug you are working on.
2. **Open only the matching module file** in `docs/codebase-map/` to inspect specific files, routes, data tables, and architectural invariants without scanning the entire repository.
3. **Trust code over the map**: if the code and this map ever disagree, the codebase is the source of truth. Always update the map file to reflect actual code behavior.

## Module Index

| Module | One-line summary | Main folders | Map file link |
| --- | --- | --- | --- |
| **Core Platform** | Multi-company isolation, users, permissions, panels, settings, ModuleGuard error shielding, backups, and deploy services. | `app/Models/Concerns`, `app/Scopes`, `app/Services`, `app/Providers/Filament`, `bootstrap` | [core-platform.md](docs/codebase-map/core-platform.md) |
| **Catalog & Inventory** | Products, variants, categories, media library, stock ledger, cross-company stock pools, purchases, and shipments. | `app/Models`, `app/Services`, `app/Filament/Resources/Products`, `app/Filament/Clusters/Inventory.php`, `app/Filament/Clusters/Purchasing.php` | [catalog-inventory.md](docs/codebase-map/catalog-inventory.md) |
| **Sales, Orders & Couriers** | Orders, quotation workflows, customer risk scoring, courier logistics APIs (Steadfast, Pathao, RedX, eCourier), and parcel scanning. | `app/Services/Couriers`, `app/Services/CourierFraud`, `app/Filament/Resources/Orders`, `app/Filament/Clusters/Sales.php`, `app/Filament/Clusters/Courier.php` | [sales-orders-couriers.md](docs/codebase-map/sales-orders-couriers.md) |
| **Accounts & Finance** | Double-entry accounts, multi-stage vouchers, trash governance, transaction ledgers, expenses, OCR receipt scans, and reports. | `app/Services`, `app/Services/ExpenseScan`, `app/Filament/Resources/Vouchers`, `app/Filament/Clusters/Finance.php`, `app/Filament/Clusters/Reports.php` | [accounts-finance.md](docs/codebase-map/accounts-finance.md) |
| **Storefront (Blade)** | Server-rendered storefront, themes (marketplace-pro, noor-solar), checkout policy, payment gateways (ZiniPay, PayStation), and Meta CAPI. | `resources/views/storefront`, `app/Http/Controllers/Storefront`, `app/Services`, `app/Filament/Clusters/Storefront.php` | [storefront-blade.md](docs/codebase-map/storefront-blade.md) |
| **API & Integrations** | Headless Next.js storefront API (`/api/v1/storefront`), website API (`/api/v1`), WooCommerce sync, Nuport import, and webhooks. | `routes/api.php`, `app/Http/Controllers/Api/V1`, `app/Services`, `app/Support/StorefrontApi` | [api-integrations.md](docs/codebase-map/api-integrations.md) |
| **Reseller Program & Portal** | Reseller portal (`/portal`), wholesale catalog, variation prices, commission settlements, deposits, payouts, and custom domain SSL. | `app/Filament/Reseller`, `app/Services`, `app/Filament/Clusters/Resellers.php`, `app/Models` | [reseller.md](docs/codebase-map/reseller.md) |
| **CRM & Omnichannel Messaging** | Leads, unified Inbox, WhatsApp Cloud & Web (QR bridge), SMS gateways, Telegram alerts, and the AI Inbox Control Plane. | `app/Services/Crm`, `app/Services/WhatsAppWeb`, `app/Filament/Clusters/Crm.php`, `app/Filament/Pages` | [crm-messaging.md](docs/codebase-map/crm-messaging.md) |
| **Investments & IMS v2** | Mudarabah investment pools, IMS Office (`/office`), Investor Portal (`/investor`), NID OCR KYC, profit proposals, and statements. | `app/Ims`, `app/Filament/Ims`, `app/Filament/Investor`, `app/Filament/Clusters/Investments.php` | [investments-ims.md](docs/codebase-map/investments-ims.md) |
| **AI & Marketing Tools** | Tool Menu, generative image/video studios, content governance, prompt enhancement, Meta Ads dashboard, and AI landing pages. | `app/Services/ImageGeneration`, `app/Services/VideoGeneration`, `app/Filament/Clusters/AiTools.php`, `app/Filament/Clusters/Ads.php` | [ai-marketing-tools.md](docs/codebase-map/ai-marketing-tools.md) |
| **Social Content Planner** | Organic Facebook/Instagram posts per company: write, approve, schedule, publish, reconcile; its own `social` queue and worker. | `app/Services/Social`, `app/Filament/Resources/SocialPosts`, `app/Jobs`, `app/Console/Commands` | [social-content.md](docs/codebase-map/social-content.md) |
| **Non-Laravel Apps & Folders** | Android POS wrapper, mobile shell, Baileys WhatsApp microservice, n8n workflows, design systems, and CI/CD pipelines. | `android/`, `whatsapp-bridge/`, `n8n Workflows/`, `ERP Frontend Design System/`, `.github/` | [other-apps.md](docs/codebase-map/other-apps.md) |

## Cross-cutting rules
Before writing any code or modifying existing behavior, you must consult the following authoritative sections in [CLAUDE.md](CLAUDE.md):
- **Multi-Company Isolation**: See `## Multi-company isolation` in [CLAUDE.md](CLAUDE.md) — every company-owned model must use `BelongsToCompany` + `CompanyScope` and pass `MultiCompanyIsolationTest`.
- **Bilingual Support (Bangla + English)**: See `## Bilingual (Bangla + English) rule` in [CLAUDE.md](CLAUDE.md) — all user-facing strings must be localized in both `lang/en.json` and `lang/bn.json` via `__()`.
- **Error Isolation**: See `## Error isolation (owner request 2026-10-08)` in [CLAUDE.md](CLAUDE.md) — widgets, navigation badges, and portal components must run inside `ModuleGuard` to isolate failures.
- **Commit & Release Policy**: See `## Commit policy` and `## Release notes are part of every commit` in [CLAUDE.md](CLAUDE.md) — commit to feature branches without merging to main; maintain `CHANGELOG.md` and `UPDATE_NOTES.md`.
- **Storefront & Coexistence**: See `## Companies and sites (owner-confirmed 2026-10-03)` and `## Headless Next.js storefront (owner decisions, 2026-10-03)` in [CLAUDE.md](CLAUDE.md).
- **WhatsApp Web Safety**: See `## WhatsApp Web (QR) channel rule` in [CLAUDE.md](CLAUDE.md) and [docs/whatsapp-web-rules.md](docs/whatsapp-web-rules.md).

## Keeping the map current
Any commit that adds, moves, or removes a model, service, panel page, route group, console command, or background job **must** update the corresponding module file in `docs/codebase-map/*.md` (and this index if a new module is introduced) in the exact same commit, updating the `> Last checked:` date and commit hash header.
