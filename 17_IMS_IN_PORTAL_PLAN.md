# 17 — IMS inside the investor portal (no separate stack)

> **বাংলা সারসংক্ষেপ:** মালিকের সিদ্ধান্ত (২০২৬-১০-১৯): IMS আলাদা অ্যাপ হবে না। আমাদের বিল্ট-ইন ইনভেস্টর পোর্টালটাই IMS হবে, একই ERP ও একই ডাটাবেসে। তাই IMS-টিমের জন্য বানানো `/api/v1/sync/*` ফিড আর "IMS থেকে পেআউট রিপোর্ট" ধাপের আর দরকার নেই (ফিডের কোড থাকবে, চাবি কখনও বানানো হবে না)। IMS-এ যা ছিল আর পোর্টালে ছিল না, তিনটা: (১) একাধিক ব্যবসা এক লগইনে, (২) প্রজেক্টের লাভের হিসাব ERP ডাটা থেকে, (৩) USD/RMB রেট ও বিদেশি মুদ্রার খরচ। মালিক তিনটাই চেয়েছেন। (৩) আগে বানানো হচ্ছে, কারণ এতে নতুন কোনো ব্যবসায়িক নিয়ম লাগে না। (১) ও (২)-এর জন্য §6-এর প্রশ্নের উত্তর লাগবে।

Status: **owner decision 2026-10-19** ("IMS টা এখন আমাদের যে বিল্ট ইন ইনভেস্টর পোর্টাল আছে সেটাকেই এখন ims এ কনভার্ট করব, আলাদা স্ট্যাক ইউজ হবে না। ims এর জন্য যে যে সেটাপ করেছিলাম সেগুলো আমাদের ইনভেস্টর পোর্টালে ইন্টিগ্রেট করতে হবে"). Owner chose all three missing pieces in §3 and chose to keep the feed code with no keys created.

## 1. What each earlier IMS setup becomes

| Built for the separate IMS | Now |
|---|---|
| Business feed `/api/v1/sync/*` (orders, payments, returns, expenses, stock) | Not needed: the data is in the same database. **Removed on `main` in v2.89.0** (the route answers 404; `ImsSyncFeed` and its tests are deleted). |
| Investor feed `/api/v1/sync/investor/*` (names, groups, project progress, payouts, screenshots, bank accounts, NID) | Already the portal's own pages (Dashboard progress, Payouts + screenshot, Bank accounts, Profile). **Removed on `main` in v2.89.0** together with the business feed (the key carried NID, phone and bank numbers). |
| "IMS reports a payout" intake (planned) | Cancelled: payouts are made in the ERP and the portal shows them. Nothing to sync. |
| Owner rule: no customer, no supplier information | Stays: the portal never shows customer or supplier data. |
| Soft deletes on expenses and order payments, returns, stock-loss value | Stay (they make the profit helper in §5 trustworthy). |
| Settle a cycle from a hand-entered profit (no sales) | Already built (2026-10-18). |
| Bank instruction | Still blocked on the bank's file format. |

## 2. What IMS has that the ERP and portal did not (from `ERP-INTEGRATION-REQUIREMENTS.md` and the second requirements note)

1. 10–20 businesses in one system → today each business has its own portal host and its own login.
2. Profit per project = sales received − real cost, worked out from data (payments with their own dates, returns, expenses, stock losses) → today staff type the revenue.
3. USD and RMB to BDT daily rates and costs entered in the original currency → today every cost is typed in BDT.

## 3. Phases

| Phase | What | Needs from the owner |
|---|---|---|
| **R — rates and foreign-currency costs** | Daily rate table (USD, RMB) per company; a project cost can be entered in USD or RMB with the rate of its date; the BDT amount is stored as before, so the settlement maths do not change. | Nothing new (defaults in §4). |
| **M — one login, many businesses** | See §5. | Answers to Q1–Q3. |
| **P — profit helper from ERP data** | **Covered by IMS v2 on `main` (v2.89.0)**: *Profit proposals* work out each cycle's profit and ask the office to accept, enter by hand or dismiss; project pages show totals from the purchases linked to the project. Not rebuilt here. | Only if the owner finds a gap (Q4–Q6 then apply). |

## 4. Phase R design (built first)

- New table `exchange_rates`: `company_id`, `currency` (`USD`, `CNY` shown as RMB), `rate_date`, `rate` (BDT for 1 unit, 6 decimals), optional `note`, `created_by`. One rate per company, currency and day. Entered by staff (Investments → Exchange Rates); **no outside rate service is called**, so nothing is guessed. Company-scoped like every company-owned model.
- `project_cost_items` gains nullable `currency`, `original_amount`, `exchange_rate`, `cost_date`. `amount` stays the BDT figure that every report and the settlement use. For a foreign cost the server sets `amount = round(original_amount × exchange_rate, 2)` and rejects a missing rate; the form fills the rate from the table (the latest rate on or before the cost date) and staff may change it.
- Costs entered before this change are BDT and are untouched.
- Not in this phase: foreign-currency purchases, a rate on the shipment, automatic rate download, other currencies (the list is one constant, so adding one is a one-line change plus its label).

## 5. Phase M design (needs Q1–Q3)

- Today: one portal login per phone per company (`investor_portal_accounts` unique on `company_id` + `phone`), host decides the company, `CompanyContext` is set from the host, so every query is already confined to one company.
- Proposed: keep that boundary. A person is the same person across businesses **when the same phone number has an active portal account in each** (the same assumption the portal already makes inside a company). After signing in, the top bar shows a **business switcher** listing the businesses where that phone has an active account; choosing one sets that business as the active company for the session. Every page still renders under one company's context at a time, so no query ever mixes companies. Optional: a "My businesses" overview page that adds up the figures by switching context business by business.
- Needs a host that is not tied to one company (a central address). The per-company hosts keep working.

## 6. Phase P design (now covered by IMS v2; kept for the record)

- A helper inside **Calculate & Settle**: "From the ERP data, this project's dates show ৳X collected". It is a **suggestion**: staff can use it, change it or ignore it; the hand-entered profit option stays, because investors are paid every cycle whether or not sales came in. Nothing is paid or settled by itself.
- The IMS formula: profit = sales actually received − (costs of goods sold + other costs). The ERP has what it needs: order payments with their own dates (deleted ones are kept visible), returns, expenses, stock losses with a value, and the cost of goods on each order line.
- The rules for **which sales and costs belong to which project** are the owner's. Sales are deliberately not linked to projects ("as it is"), so a project cannot pick its own sales; a rule must say how a business's sales in the project's dates are shared when more than one project runs at once.

## 7. Open questions for the owner

1. **Q1 — Central address:** which address should hold the one sign-in for all businesses (for example `ims.<domain>`)? Do the per-business portal addresses stay?
2. **Q2 — Sign-in:** one phone number = one person across businesses (as inside one business today)? Or should staff link accounts by hand?
3. **Q3 — Overview:** should the investor also see one page that adds up all their businesses, or only switch between them?
4. **Q4 — Period:** use the project's start and end dates as the period?
5. **Q5 — Sharing:** when a business has more than one project running in the same dates, how is the business's result shared: by capital, by the project's purchase link, or one project per business at a time?
6. **Q6 — Which sales count:** payments received in the period on sales that are confirmed, processing or completed (returned orders excluded)? And which costs: the project's own cost items only, or also expenses, stock losses and the cost of goods sold?

## 8. Build log

- **R — built 2026-10-19.** `exchange_rates` table and *Investments → Exchange Rates* page; `project_cost_items.currency / original_amount / exchange_rate / cost_date`; the model computes `amount` (BDT) on save. Differences from the plan: none. Not done: foreign-currency purchases and shipment costs, rate download.
- **R inside IMS v2 — 2026-10-20.** `main` gained IMS v2 (v2.89.0, own domain and `/office`). `ExchangeRateResource` is registered in the office panel and listed in the office menu under Tools, so the page is reachable once a company runs IMS and the old `/admin/investments/exchange-rates` address redirects to it. Merge with `main` conflicted only in documents and language files.
- **P — not built here:** IMS v2's profit proposals do this job.
- **M — not built:** waiting for the owner's answers to Q1–Q3. IMS v2 still gives each company its own IMS domain, so one sign-in across several businesses is not covered.
