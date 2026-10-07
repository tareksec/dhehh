# Agent Rules (Claude Code)

These rules are mandatory for any AI agent working in this repository.

## Release notes are part of every commit

- **Before any `git commit` / `git push`, write the release note first.** Add or update the entry in `CHANGELOG.md` describing the change (follow `docs/release-policy.md`: categorize as major, minor, patch, security, hotfix, or maintenance, with Added/Fixed/Security/Technical Notes sections). A commit without a matching CHANGELOG entry is not allowed.
- Also keep the working log in `UPDATE_NOTES.md` current — it describes what a pending commit contains, ending with the commit status line.

## Commit policy

- Never `git commit` or `git push` without the owner's explicit approval in the conversation.
- Never run destructive commands (`migrate:fresh`, broad seeders) against non-demo databases.

## Business rules

- Never invent demo/placeholder business rules — real rules only, gathered from the owner. External credentials (payment, SMS, WhatsApp, WooCommerce) are always admin-configurable encrypted settings fields; the owner plugs in keys.
- Storefront-visible content must be manageable from the Filament admin panel, never hardcoded in views.

## Companies and sites (owner-confirmed 2026-10-03)

- **zamzamint.com = ZamZam International, the wholesale (পাইকারি) site. zamzamgadgetbd.com = ZamZam Gadget, the retail (খুচরা) site.** They share physical stock through a `StockPool`.
- `Company::CORE_COMPANIES` and `docs/storefront-go-live.md` still label zamzamint.com "Gift Items" and zamzamgadgetbd.com "Gadget Items". Those labels are stale seed defaults — never use them to decide which business a site belongs to; ask or follow this section.

## Headless Next.js storefront (owner decisions, 2026-10-03)

- The rebuilt zamzamint.com website (repo `sajiduzzaman-im/zamzam-international`; working fork `bhd4232/zamzamint-new-website-theme`, Next.js 14, frontend-only) uses this ERP as its **only** backend. It is hosted on **Coolify**.
- The ERP **Blade storefront is not retired**. **Owner decision 2026-10-07 (replaces the 2026-10-03 "one shared row"):** the **Blade storefront is the reseller store** and the **Next.js site is the main storefront**, and each has its **own row** in the site list (admin page **Site → Storefront Settings**, `StorefrontSettingResource`) so each can be customised on its own. `storefront_settings.site_type` is `blade` (default — every row that existed before) or `nextjs`; unique per `(company_id, site_type)`, so a company has at most one of each. Everything on a row (theme, content, checkout rules, payment gateway, SMS, Meta pixel, website webhook) belongs to that site; the Next.js row also carries `nextjs_site_enabled` / `nextjs_site_domain` / `nextjs_site_domain_verified`, while the Blade domain stays on `companies.domain`. Code that predates the split keeps reading `$company->storefrontSetting`, which is the **Blade** row (reseller program, WooCommerce, CRM messaging, reminders); `$company->nextjsSetting` / `StorefrontSetting::nextjsFor()` is the Next.js row and is what `/api/v1/storefront/*` serves (`EnsureNextjsSiteEnabled`). Orders and payments started on the Next.js site store `storefront_setting_id` so payment webhooks and Meta tracking use that site's keys (`Order::siteSetting()`, `StorefrontPayment::siteSetting()`; NULL = Blade). Still company-level and shared by both sites: products, stock, customers, hero slides, pages, offers, payment-method list, the API key. Zam Zam International's Blade storefront runs on **offer.zamzamint.com**; the Next.js site takes **zamzamint.com** (staging **new.zamzamint.com**).
- The rebuilt frontend (currently branded "Zamzam Gadget", retail-style) is to be turned into the **wholesale** ZamZam International site (owner confirmed 2026-10-03).
- Next.js talks to the ERP server-side only (Bearer company API key in a server env var, never `NEXT_PUBLIC_*`); ERP webhooks (`product.updated`, `order.status_updated`) revalidate the Next.js cache. The existing `routes/api.php` v1 API is server-to-server and too thin for a storefront (no categories/images/slides/settings, no delivery/VAT/advance/online-payment checkout) — extend it, don't expose it to browsers.
- Flash Sale / Trending / New-arrival flags are **admin-managed through new options in the Offers section** — do not invent product-level flags or auto rules.
- Checkout parity: the Next.js checkout must apply every rule the Blade `Storefront\CheckoutController` applies (delivery area/fee, VAT, new-customer delivery advance, pre-order advance, courier-risk advance, ZiniPay/PayStation online payment). Move that logic into a shared service used by both; never re-implement it. Prices are always computed server-side.
- The Next.js site is **Bangla + English**; all storefront-visible content (hero slides, banners, footer, free-delivery threshold, etc.) comes from the ERP admin, not hardcoded in the frontend. Wholesale needs (MOQ, tier prices, business-account callouts) apply to the zamzamint.com site.
- Planned order: (1) read-only catalog API + frontend wiring — **built and committed 2026-10-03** (`docs/api/storefront-api.md`), (2) quote/checkout/payment — **built 2026-10-04** (`StorefrontCheckoutService` shared by Blade and the API, `Api\V1\StorefrontCheckoutController`; commit status in `UPDATE_NOTES.md`), (3) customer login/order tracking/reviews — **built 2026-10-06** (`Api\V1\StorefrontAccountAuthController` / `StorefrontAccountController` / `StorefrontOrderTrackingController`, contract `docs/api/storefront-api.md` §6; commit status in `UPDATE_NOTES.md`), (4) DNS cutover on Coolify. ERP-side work follows every rule in this file (CHANGELOG, bilingual keys, company isolation, tests).
- **Customer login on the Next.js site (2026-10-06):** the ERP issues an opaque per-customer token (`storefront_customer_tokens`, hash only); the Next.js server keeps it in an httpOnly cookie and sends it as `X-Customer-Token` with the company API key — never to browser scripts. Accounts reuse `CustomerAccountService` and obey `customer_accounts_enabled`; order tracking is public (order number + the order's phone, or the order token); a review starts pending and needs the owner's approval. Phones are normalised to `01XXXXXXXXX` before any lookup. Known open decision: registration adopts the existing customer record of the same phone without proving ownership (Blade parity) — see `UPDATE_NOTES.md`.
- **Price rule (owner decision 2026-10-04):** the server charges variation price → live Collection **deal price** → wholesale **tier price** → selling price. A live deal wins over tiers; a deal not lower than the selling price is ignored; variations never get a product-level deal. The Next.js checkout re-prices every line on the server (`StorefrontCatalogService::unitPrice()`), and the visitor's IP is forwarded as `client_ip` (never the Next.js server's). Admin-entered content (product names, descriptions, banners) is not translated yet — that is `docs/i18n-bangla-english-plan.md` Phase 4, so the Next.js site shows it as entered, with only its interface text in Bangla + English.
- The Next.js site's colour/fonts come from the ERP (`/site` → `theme`); its brand colour scale is derived from the primary colour. Background/surface/text palette parity with the Blade site is not done yet.

## Bilingual (Bangla + English) rule

- Follow `docs/i18n-bangla-english-plan.md` (section 0 applies to every task, not only i18n work).
- Every new or changed user-visible string goes through `__('English text')` and is added to **both** `lang/en.json` and `lang/bn.json` in the same commit, with identical `:placeholders`. `TranslationParityTest` enforces this.
- Never hardcode Bangla in Blade/PHP (only in `lang/bn.json`); admin-entered content stays admin-managed and is translated per the plan's Phase 4, not in lang files.
- If a correct Bangla translation is uncertain, still add the key (English text as the value) and list it under "Needs Bangla review" in `UPDATE_NOTES.md`.

## Multi-company isolation

- Every new company-owned model must use `BelongsToCompany` + `CompanyScope` and be added to `MultiCompanyIsolationTest::test_every_company_owned_model_uses_the_company_scope_contract`.
- `SetCurrentCompany` must stay pinned before `SubstituteBindings` in `bootstrap/app.php`; new queued jobs set/clear `CompanyContext` explicitly; scheduled commands loop per company.

## Test runs must never touch demo/dev data

- Running tests must NEVER refresh, reseed, or otherwise modify the demo/development database.
- **Always run tests as plain `php artisan test` (or `--filter=...`) with NO `--env` flag.** Verified 2026-07-05: `php artisan test --env=testing` bypasses `phpunit.xml`'s env overrides and runs `RefreshDatabase` against the real `database/demo.sqlite` (env=local, file cache) — wiping demo data. Plain `php artisan test` correctly uses the in-memory sqlite + array cache + testing env from `phpunit.xml`.
- Never run `demo:refresh`, `db:seed`, `migrate:fresh`, or any seeder as part of testing or verification. The only place demo refresh may execute is inside the test suite's own isolated-database test (`DemoDataSeederTest`), which must keep using a disposable database.
- If a test needs data, it creates its own records under `RefreshDatabase`; never rely on or mutate seeded demo data.

## Verification before handoff

- Run the affected test files plus the full `php artisan test` suite, and `npm run build` when frontend assets change.
