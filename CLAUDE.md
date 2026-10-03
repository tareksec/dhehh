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
- The ERP **Blade storefront is not retired**. It stays alongside the Next.js site in the site list, and both sites keep working off the existing per-company storefront configuration/settings exactly as today. The "site list" is the admin page **Site → Storefront Settings** (`StorefrontSettingResource`). Constraints found 2026-10-03: `storefront_settings.company_id` is `unique` (one row per company) and the domain lives in `companies.domain` (also one per company, uniqueness enforced in the create/edit pages) — so a second site for the same company does not fit as a second row without a model change. ZamZam International's Blade storefront currently runs on **offer.zamzamint.com** (owner screenshot); the Next.js site takes **zamzamint.com**.
- The rebuilt frontend (currently branded "Zamzam Gadget", retail-style) is to be turned into the **wholesale** ZamZam International site (owner confirmed 2026-10-03).
- Next.js talks to the ERP server-side only (Bearer company API key in a server env var, never `NEXT_PUBLIC_*`); ERP webhooks (`product.updated`, `order.status_updated`) revalidate the Next.js cache. The existing `routes/api.php` v1 API is server-to-server and too thin for a storefront (no categories/images/slides/settings, no delivery/VAT/advance/online-payment checkout) — extend it, don't expose it to browsers.
- Flash Sale / Trending / New-arrival flags are **admin-managed through new options in the Offers section** — do not invent product-level flags or auto rules.
- Checkout parity: the Next.js checkout must apply every rule the Blade `Storefront\CheckoutController` applies (delivery area/fee, VAT, new-customer delivery advance, pre-order advance, courier-risk advance, ZiniPay/PayStation online payment). Move that logic into a shared service used by both; never re-implement it. Prices are always computed server-side.
- The Next.js site is **Bangla + English**; all storefront-visible content (hero slides, banners, footer, free-delivery threshold, etc.) comes from the ERP admin, not hardcoded in the frontend. Wholesale needs (MOQ, tier prices, business-account callouts) apply to the zamzamint.com site.
- Planned order: (1) read-only catalog API + frontend wiring — **built and committed 2026-10-03** (`docs/api/storefront-api.md`), (2) quote/checkout/payment, (3) customer login/order tracking/reviews, (4) DNS cutover on Coolify. ERP-side work follows every rule in this file (CHANGELOG, bilingual keys, company isolation, tests).
- Decisions still open for phase 2: how a live Collection **deal price** combines with wholesale **tier prices** (the website currently shows the deal price when there is one, else the tier price); the Next.js checkout must re-price every line on the server. Admin-entered content (product names, descriptions, banners) is not translated yet — that is `docs/i18n-bangla-english-plan.md` Phase 4, so the Next.js site shows it as entered, with only its interface text in Bangla + English.
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
