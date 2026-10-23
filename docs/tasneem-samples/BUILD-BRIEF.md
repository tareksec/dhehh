# Build brief — Tasneem Trade Portal (for the building agent)

Read in this order, nothing else is needed to start:

1. `CLAUDE.md` (mandatory rules: CHANGELOG + UPDATE_NOTES before commit, bilingual `__()` keys in `lang/en.json` + `lang/bn.json`, `BelongsToCompany` + `CompanyScope` + `MultiCompanyIsolationTest`, `ModuleGuard` / `GuardsWidgetRendering` / `HasGuardedNavigationBadge`, tests with plain `php artisan test`, never touch demo DB, feature branch only, never push or merge to `main` without the owner's approval in chat).
2. `CODEBASE_MAP.md`, then only the module files you touch: `docs/codebase-map/core-platform.md`, `catalog-inventory.md`, `sales-orders-couriers.md`, `accounts-finance.md`, `investments-ims.md` (the model for a company-switchable panel). Update the matching map file and its "Last checked" line in the same commit.
3. `docs/tasneem-samples/PLAN.md` (decisions, phases) and `SUMMARY.md` (exact fields, formulas, fixed texts of the 9 import documents).
4. `docs/tasneem-samples/delivery-challan-sample.webp` (the sale paper) and the 9 original files in the same folder.

Owner chats in simple Bangla; code, commits and CHANGELOG stay English. The end user is a non-technical offline-business owner: phone first, Bangla first, very simple.

## What to build

A new Filament panel `trade` at `/trade` on the main domain for any company that has it switched on (company setting, like `ims_enabled`; Tasneem Knitting Industry is the first). Same code base and same staff `users` accounts (guard `web`); a role decides access. Pattern to copy: `app/Providers/Filament/ImsOfficePanelProvider.php` (bottom tab bar `filament.ims.office.mobile-nav`, theme `resources/css/filament/ims/`, `OfficeNavigation`).

### Phase 1 (do first, it is also a security fix)
- `User::canAccessPanel` (`app/Models/User.php`) returns `true` for every active user on any non-office panel. Add a `trade` branch (permission e.g. `trade.access` + company has the portal on) and stop users with only the trade role from entering `/admin`. Keep existing admin users unaffected; add tests for both directions.
- `RestrictPortalHosts` (`app/Http/Middleware/RestrictPortalHosts.php`) 404s unknown portal paths on non-portal hosts: allow `trade`.
- Company flag + migration (MySQL 8.4 safe, index names <= 64 chars), company settings toggle, create role `trade_owner` (editable in Users -> Roles), seed nothing into non-demo data.
- Panel shell: Bangla-first, mobile bottom tabs, light theme, home page with four cards (today's sales, dues, low stock, LC stage list), empty state friendly.
- Company profile fields: registered trade address and factory/letterhead address, TIN, BIN (per use: the challan shows `006673859-0403`, import papers `006673859-0112`, confirm), IRC, bank accounts, signatory, letterhead image.

### Later phases (details in PLAN.md section 7)
2. Shop work: receive purchases, Delivery Challan (sale; mixed machine + parts lines; advance, cash paid, due, cheque no., due terms; printable on letterhead), stock, receivables, CSV/Excel import of old data. Reuse `Purchase*`, `StockMovementService`, `Order`/`Customer`, `LowStockAlertService`.
3. Cash book + simple balance sheet over the existing accounts/vouchers (no new ledger).
4. LC file + document generator: LC opening letter, DRL, Beneficiary's Certificate, Shipment Advice, Detail Packing List; PI/CI/PL already exist in `PurchaseDocumentController`. English wording and fixed clauses exactly as in `SUMMARY.md`; marine cover note no. + date mandatory before printing a Shipment Advice.
5. Landed cost (manual cost lines per LC file: duty/insurance/C&F from the C&F agent, truck and loading from the owner) + optional machine serial numbers.
6. Reports as screen + PDF + Excel: daily/monthly sales, stock ledger, receivables aging, supplier payable, LC status, landed cost, shipment advice register, daily cash and bank.

## Do not
- Do not copy ERP admin screens into the portal; build small, plain pages that call the same services.
- Do not hardcode business rules or demo data; weights are per shipment, never global.
- Do not touch the demo database; no `migrate:fresh`, no seeders in verification.

## Done for each phase
Tests pass (`php artisan test`, no `--env`), `npm run build` if assets changed, CHANGELOG + UPDATE_NOTES updated, bilingual keys added, codebase map updated, committed and pushed to `feature/trade-portal-<phase>`. Check the pages in the browser at phone width before handing over.

## Open (does not block Phase 1)
- Real duty / insurance / C&F figures of one old shipment (owner gets them from the C&F agent) to verify the landed-cost formula.
- Which BIN is printed on which document.
