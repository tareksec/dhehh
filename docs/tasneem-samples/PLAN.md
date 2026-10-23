# Tasneem Knitting Industry — Trade Portal Plan

Status (2026-10-22): phase 1 (foundation) built on `feature/trade-portal-foundation`; phase 2 (shop work) on `feature/trade-portal-shop-work` (branched from phase 1); phase 3 (cash book + balance sheet) on `feature/trade-portal-cash-book` (branched from phase 2); phase 4 (LC file + documents) on `feature/trade-portal-lc-file` (branched from phase 3); phase 5 (landed cost + machine serials) on `feature/trade-portal-landed-cost` (branched from phase 4); phase 6 not started. Source: `SUMMARY.md` (9 import documents), the handwritten Delivery Challan photo, and the owner's voice answers.

## 1. Decisions

- Tasneem Knitting Industry is its **own company** in the ERP. Data stays isolated (`CompanyScope`).
- A new Filament panel, **Trade Portal** (`/trade`, on the main domain, no separate domain). Staff sign in with email + password; a role decides what they see. Built so **any company can be switched on with one toggle** (company setting, like `ims_enabled`).
- The `/admin` panel stays closed to this role (`User::canAccessPanel` currently returns true for every active user on non-office panels — must be fixed first). `RestrictPortalHosts` must allow the `trade` path.
- Mobile-first, lightweight, **Bangla first**, plain shop words ("মাল এলো", "বিক্রি", "বাকি"). One screen = one job, big buttons. Same code base, reuses existing services.
- Customisable role: a new role (e.g. `trade_owner`) with permissions the Super Admin can edit in Users → Roles.

## 2. Owner's answers (2026-10-22)

| Question | Answer | Consequence |
|---|---|---|
| Sale paper | **Delivery Challan** (hand-written pad, SL no., see §4) | Build a Delivery Challan as the sale record: print on his letterhead |
| Import papers (PI, Commercial Invoice, Packing List, Certificate, Shipment Advice, LC letter, DRL) | **He prepares them himself** | System generates all as drafts from one LC file; he enters data once |
| Customs duty, insurance, C&F cost | Comes from the C&F agent; owner collects the figures | Manual cost lines per LC file, with source ("C&F agent") |
| Truck fare, loading/unloading | He keeps these figures himself | Manual cost lines per LC file |
| Cash book | Wants it **together with** purchase / stock / sales: daily sales, bank deposit, other costs, party payment, bank name, date, ending in a **smart balance sheet** | Simple Cash Book view over the existing ledger (see §5) |
| Machine vs parts | Sold together on one challan (machine + extra CAM, feeder, stand) | Challan lines are mixed items; stock is per item; serial no. optional on machines |

Still open (does not block start): the actual duty / insurance / C&F figures of one old shipment, to check the landed-cost formula. Owner will get them from the C&F agent.

## 3. Reuse vs new

Already in the ERP (reuse, extend): `Purchase` / `PurchaseItem` (LC/PI/CI refs, landed cost), PI / CI / Packing List PDFs (`PurchaseDocumentController`), `Shipment`, `Container`, `Supplier`, `StockMovement` ledger, `LowStockAlertService`, `Order` / `Customer`, vouchers, accounts and ledgers, CSV import.

New:
1. Trade Portal panel `/trade` + company switch + role.
2. **LC file** (import file): supplier, PI, LC (no., date, bank, margin %), insurance cover note, PSI, vessel / B/L / container / seal, CI, document status and stage.
3. Document generator: LC opening letter, DRL, Beneficiary's Certificate, Shipment Advice, Detail Packing List (PI/CI/PL already exist). English wording, fixed clauses from the samples, numbers via `NumberToWords`.
4. Company profile additions: two addresses (registered trade address vs factory/letterhead), TIN / BIN / IRC, bank accounts, signatory, letterhead.
5. Delivery Challan (sale record with advance, cash paid, due, cheque no., due terms, signatures).
6. Landed cost worksheet.
7. Machine serial numbers (optional per unit).
8. Cash Book + balance sheet view.
9. Reports (§6).

## 4. Delivery Challan (from the photo)

Fields: Challan SL no. (026), date, buyer / party name, address, lines (description, quantity + unit, remarks), price (unit × qty), advance, cash paid (date), due, cheque no. + bank, due terms ("within next 2 months"), receiver signature, authorised signature (name, designation, phone), BIN, bilingual disclaimer, letterhead.
Example check: 7,50,000 × 2 = 15,00,000; advance 1,00,000 + cash 8,00,000 + due 6,00,000 = 15,00,000.
Rules: stock reduces when the challan is confirmed; due goes to the customer's receivable and to the aging report; payments later are added against the same challan.
**Note:** the challan shows BIN `006673859-0403`; the import documents show `006673859-0112`. Keep BIN per document type in the company profile, confirm with the owner which is used where.

## 5. Cash Book (smart, one screen)

Daily entries: sale receipt, bank deposit (bank name, date), party payment (supplier), other cost (category), cash in hand. Built on the existing accounts / vouchers, not a new ledger. Output: daily cash book, monthly summary, simple balance sheet (cash, bank, stock value, receivables, payables).

## 6. Reports

Daily sales, monthly sales, stock (opening + purchase − sold = closing, reorder alert), receivables aging, supplier payable, LC status pipeline, landed cost per shipment, insurance / shipment advice register, daily cash and bank. Each as screen + PDF + Excel.

## 7. Phases (one feature branch each: `feature/trade-portal-*`)

1. **Foundation:** Tasneem company, role, `/trade` shell (Bangla, mobile), company toggle, `canAccessPanel` fix, home page.
2. **Shop work:** purchase receive, Delivery Challan (sale), stock, receivables, Excel / old-data import.
3. **Cash book + balance sheet.**
4. **LC file + document generator.**
5. **Landed cost + machine serials.**
6. **Reports (PDF / Excel) + phone testing.**

Every phase: CHANGELOG, `UPDATE_NOTES.md`, bilingual keys, tests, codebase-map update, `php artisan test`.

## 8. Risks

- Open `canAccessPanel` for other panels (security) — fix in phase 1.
- Two BINs, two addresses — modelled per document type.
- Weights per shipment, never global (they differ between shipments).
- Make marine cover note no. and date mandatory before printing a Shipment Advice (a sample left it blank).
