# 15 — Investor Portal (Mudarabah): Implementation Plan

> **বাংলা সারসংক্ষেপ:** মালিকের খসড়া "12 — Investor Portal (Mudarabah) Module Plan" (২০২৬-১০-০৭) অনুযায়ী Tasneem Knitting Industry-র বিনিয়োগকারী ও চ্যানেল পার্টনারদের জন্য একটা আলাদা পোর্টাল তৈরি হবে। পোর্টালটা মোবাইলে ব্যবহারের উপযোগী হবে। লগইন হবে ফোন নম্বর + পাসওয়ার্ড অথবা SMS OTP দিয়ে। বিনিয়োগকারী নিজের বিনিয়োগ, লাভ, স্টেটমেন্ট, পেমেন্ট ও ডকুমেন্ট দেখবেন। তিনি টপ-আপ, উইথড্র, রিইনভেস্ট ও ব্যাংক অ্যাকাউন্ট পরিবর্তনের অনুরোধ পাঠাতে পারবেন। চ্যানেল পার্টনার দেখবেন তাঁর আনা বিনিয়োগকারী ও তাঁর কমিশন। ERP-তে আগে থেকেই একটা পূর্ণাঙ্গ **প্রজেক্ট-ভিত্তিক** মুদারাবা মডিউল আছে (Project-01-এর ১৯ জন বিনিয়োগকারীর সেটেলমেন্ট সহ)। তাই পোর্টাল সবকিছু নতুন করে বানাবে না, বিদ্যমান ডেটার ওপরেই দাঁড়াবে। খসড়ার সবচেয়ে বড় পরিবর্তন হলো পুরো কোম্পানির মাসিক/ত্রৈমাসিক লাভ "মূলধন × দিন" অনুপাতে ৩৫:৫৫:১০ হারে ভাগ করা। কিন্তু বর্তমান চুক্তিপত্রের নিয়ম আলাদা: লাভ প্রজেক্ট-ভিত্তিক, শুধু সরাসরি খরচ বাদ যায়, আর ভাগ ৪০:৫০:১০। তাই এই নতুন হিসাব একটা আলাদা **"পুল" চুক্তি-ধরন** হিসেবে আসবে, এবং শুধু §৩.৩-এর প্রশ্নগুলোর উত্তর পাওয়ার পরে। কাজ ৮টা ধাপে ভাগ করা। প্রথম ৪টা ধাপ (পোর্টাল, স্টেটমেন্ট, অনবোর্ডিং, অনুরোধ) বর্তমান নিয়মেই চলবে এবং প্রতিটা আলাদাভাবে লাইভ করা যাবে।

Status: **Phases 1–5 built** (1–2 on 2026-10-07, 3–5 on 2026-10-08; Phase 5 without the NCC BEFTN file) — see §15. The NCC file (P10), Phases 6–7 wait for the owner answers in §3 (P10, P11, D1–D10).

Source: the owner's draft *12 — Investor Portal (Mudarabah) Module Plan* (shared in the session on 2026-10-07; summarised in §1.2 so this file is self-contained). This plan maps that draft onto the code as it is today. It keeps every owner rule already recorded in `01_INVESTOR_MUDARABAH_MODULE_PLAN.md` and `01_INVESTOR_MODULE_v3_GAP_ANALYSIS.md`, and copies the structure of the reseller portal (`13_RESELLER_PORTAL_PLAN.md`), which is already live.

---

## 1. Starting point

### 1.1 What already exists (verified from code, 2026-10-07)

| Area | Today | The portal uses it for |
|---|---|---|
| Company | Tasneem Knitting Industry is the core company `garments-machinery` (`tasneemknitindustry.com`). Per `CLAUDE.md`, the seed label "Garments Machinery Company" is not used to decide anything. | The portal host (`investor.tasneemknitindustry.com`) |
| Investors | `Investor`: company-scoped. NID is encrypted and `nid_hash` is unique per company. Also has guardian, date of birth, pseudonym (`display_name`), nominee fields, stamp/cheque numbers, `channel_partner_id`, `documentChecklist()`, `investmentStatus()` | Profile and KYC |
| Channel partners | `ChannelPartner`: a separate record since 2026-09-28 (owner). Has NID, payout method with bank/MFS fields, `is_active`, `referredCapital()`, `totalEarned/Paid/Due()` | The draft's "Referrer" |
| Projects and investments | `InvestmentProject`: deal, trade cycle, investment window, split configurable per project (default 40/10/50), company gap-fill capital, fund sources. `Investment`: amount, method, date, `status` active/withdrawn plus a withdrawal voucher | "My investments" |
| Settlement | `SettlementService`: draft → confirmed → paid_out, void, loss outcomes (deed clause 4), rate per lac, annualized return on a 360-day year. `SettlementPayout` holds principal + profit + recipient bank. `ChannelPartnerPayout` holds the partner's share | Final profit and payout history |
| Elections and notices | `InvestorCycleElection` (withdraw / reinvest / partial reinvest; creates the rollover investment) and `InvestmentWithdrawalNotice` (60 days). Both are entered by staff today | Investor requests (the portal creates them) |
| Documents | `InvestmentDocument`: polymorphic, private storage, categories include investor/nominee NID, project contract, signed contract, investor report. Has an authenticated download controller | Documents page and KYC uploads |
| Reports | `InvestmentReportController`: a printable HTML report per investor payout, and the project register. dompdf is not used because it cannot shape Bangla conjuncts | Statements, certificate, agreement copy |
| Accounting link | `InvestmentLedgerService` books pending `capital_investment` and `investor_payout` vouchers. `VoucherLinkedPayoutsService` marks a payout unpaid again when its voucher goes to Trash, and paid again on restore | Payout batches |
| Import queue | `InvestorDraft` (Excel/CSV/pasted text) → review → `InvestorImportService::confirm()` | Reviewing self-registrations |
| Second-panel precedent | Reseller portal: Filament panel `reseller` at `/portal`, on host `reseller.<domain>` or `companies.reseller_portal_domain`. Uses `RestrictResellerPortalHost` + `ResolveCompanyFromResellerPortalDomain`, an SMS-code password reset, and the `customer` guard | The skeleton of the investor portal |
| OTP, SMS, WhatsApp | `CustomerAccountService::sendLoginOtp()/verifyLoginOtp()`: hashed code, 10 minutes, 5 tries, one send per minute. `StorefrontNotificationService::sendSms()/sendWhatsAppTemplate()` uses the company's `StorefrontSetting::primaryFor()` credentials | OTP login and notifications |
| Audit | `AuditLogService::record()` + `AuditObserver`. Only **staff users** are recorded as the actor; a portal user is stored as null | Needs extending (§4.9) |
| Permissions | `investments.view / manage / settle / manage_channel_partner / override_investment_window` | Plus new ones (§7.4) |
| Phone format | `App\Support\StorefrontApi\Phone` normalises to `01XXXXXXXXX` | Login and accounts |

The stack is **Laravel 13, Filament 5, PHP 8.4**. The draft says Laravel 12 / Filament 4, which is out of date.

### 1.2 The owner's draft in short

- Profit split per investment contract: investor 35%, Tasneem Knitting Industry 55%, referrer 10% (`investor_pct`, `company_pct`, `referrer_pct`; they must sum to 100).
- A separate Filament panel at `/investor` with its own `investor` guard. Login by phone + OTP, plus a password. Every query is scoped to the investor. Mobile-first. Referrers use the same panel, and their role decides which pages they see.
- Onboarding: the admin creates the investor, or the investor self-registers through a referrer's invite link and the admin verifies. KYC: NID, photo, nominee, bank account with BEFTN routing number, optional MFS number. Status `pending → verified → active` (or `rejected`). A Mudarabah agreement is accepted in the portal, with timestamp and IP logged.
- Referrer: own profile, KYC and bank account; a unique invite link/code; sees the investors they brought, each investor's capital, and their 10% per period. Paid through the same payout batch.
- Contract: amount, start date, tenure, lock-in, split, referrer, payout frequency (monthly/quarterly), profit option (payout/reinvest), status (`active`, `matured`, `withdrawn`, `closed`).
- **Calculation:** at month or quarter end, Tasneem's net profit is computed from ERP sales, COGS and expenses, and the admin locks it. Each investment's weight is capital × days active. Its pool is net profit × weight ÷ total weight, then split 35/10/55. Maker-checker approval; after lock, corrections only by adjustment entries. A loss is deducted from capital and kept in a loss ledger. The live estimate is shown separately from final profit. Rounding remainder goes to the company.
- Dashboard: totals, a card per investment, monthly chart, company performance, ledger statement, payout history, documents (agreement, period statements, certificate).
- Requests: top-up, withdrawal (lock-in checked), reinvest on/off, bank change, support. `pending → approved / rejected` with notes and audit.
- Payout: a batch is generated after each lock. Approval leads to a BEFTN bulk file or NCC Host-to-Host, through a `payout_batches` table "shared with the Reseller module". Reinvest-enabled profit goes to capital. SMS + WhatsApp messages. An audit entry on every financial action.
- 11 tables, 4 build phases, 6 open questions.

---

## 2. Where the draft and the current system differ

| # | Draft | System and signed documents today | What this plan does |
|---|---|---|---|
| G1 | Profit is Tasneem's **whole-company** net profit for each month/quarter (sales − COGS − expenses), shared by capital × days | Profit is **one deal's** selling amount minus that deal's **direct costs only**. Deed clause 3 and channel agreement clause 2 exclude overhead such as rent and salaries. It is settled once, at the end of the trade cycle | These are two different contracts. The project settlement stays as it is. The period engine is added as a second contract kind, **pool** (Phase 7), only after D1–D10 are answered. Portal phases 1–4 work on today's data. |
| G2 | Split 35 : 55 : 10 | Default 40 : 50 : 10, confirmed by the signed deed and the settlement sheet (2026-07) | The split is already stored per project. A pool gets its own default, and each investment can override it. Past contracts are never changed. D2. |
| G3 | The whole net profit is divided over **investor** capital only (the worked example has no company capital) | In Project-01 the company's own ৳9 lakh gap-fill earned profit at the pool rate (`company_contribution_amount`, owner 2026-09-07) | If the company's own money works in the same business, its share must come out before the investor pool is split. Otherwise investors are paid profit that the company's own capital earned. D3. |
| G4 | `payout_batches`, NCC BEFTN and H2H "shared with the Reseller module" | **None of these exist.** Reseller withdrawals and investor payouts are paid by hand, then marked paid with a voucher | `payout_batches` is built new and generic (Phase 5); reseller withdrawals can join later. The NCC file format comes from the bank's own template (P9) and is never guessed. H2H only after the bank onboards the company (Phase 5b). |
| G5 | A separate `referrers` table | `channel_partners` already is that table (owner, 2026-09-28) | Reuse it; add an invite code and a portal login. |
| G6 | A separate `investor_kyc` table | The KYC fields are already on `investors` (encrypted) and in `investment_documents` | Add status columns only. The photo becomes a document category. |
| G7 | Panel at `/investor` | The admin host is shared by every company, and the reseller portal runs on its own host with a host guard | Path `/investor` on host `investor.<company domain>` (or a custom domain). The investor portal never opens on the admin, shop or reseller host. |
| G8 | Agreement "generated as PDF" | dompdf cannot shape Bangla, so every investor document is printable HTML | Printable HTML with browser "Save as PDF", plus the Android app's print bridge, the same as today's reports. |
| G9 | Audit "who" on every action | The audit log records staff only | Add actor columns so investor and partner actions are attributed (also fixes the same gap on the reseller portal). |
| G10 | Open questions in the draft's §12 | **Already answered for project contracts:** a direct investor's 10% stays with the company (2026-09-07, Q2). A partner gets nothing in a loss (loss outcomes pay the partner 0). Early exit needs 60 days' written notice (deed clauses 5–6; already tracked) | Reused for project contracts as they are. For pools they must be confirmed (D5, D6, D9). |
| G11 | Company performance (revenue, expenses, net profit) shown to every investor | Investors see only their own deal's cost sheet | The owner decides how much is shown (D7). |
| G12 | Investors self-serve in the portal | Investors are created by staff and documents are on paper | Existing investors get an account when staff invite them. Nothing changes for investors who never use the portal. |

---

## 3. Owner decisions (মালিকের সিদ্ধান্ত)

Each question has my recommendation. "P" questions are about the portal; "D" questions are about the new profit-distribution engine.

### 3.1 Before Phase 1 (portal skeleton)

- **P1. ঠিকানা:** পোর্টাল খুলবে `investor.tasneemknitindustry.com/investor`-এ, অর্থাৎ কোম্পানির ডোমেনের আগে `investor.`। চাইলে Company সেটিংসে একটা সম্পূর্ণ আলাদা ডোমেনও বসানো যাবে। এটা ঠিক আছে? *(সুপারিশ: হ্যাঁ, রিসেলার পোর্টালের মতোই।)*
- **P2. লগইন:** ফোন + পাসওয়ার্ড এবং ফোন + SMS OTP, দুটোই থাকবে। SMS যাবে Tasneem-এর স্টোরফ্রন্ট সেটিংসে দেওয়া SMS গেটওয়ে দিয়ে। Tasneem-এর SMS গেটওয়ে কি সেট করা আছে, নাকি নতুন করে লাগবে? (না থাকলে শুধু পাসওয়ার্ড লগইন চলবে।)
- **P3. বিদ্যমান বিনিয়োগকারী:** অ্যাডমিন "পোর্টাল চালু করুন" চাপলে বিনিয়োগকারীর ফোনে SMS-এ একটা লিংক যাবে, সেখান থেকে তিনি নিজে পাসওয়ার্ড সেট করবেন। এটা ঠিক আছে? *(সুপারিশ: হ্যাঁ।)*
- **P4. চ্যানেল পার্টনার কী দেখবেন:** তাঁর আনা বিনিয়োগকারীদের নাম (ছদ্মনাম থাকলে সেটা), মূলধন, যোগদানের তারিখ ও তাঁর কমিশন। ফোন, NID বা ঠিকানা দেখাবে না। এটা ঠিক আছে?
- **P5. নাম:** পোর্টালে চুক্তিপত্রের শব্দ "চ্যানেল পার্টনার" রাখব, নাকি খসড়ার "রেফারার"? *(সুপারিশ: চ্যানেল পার্টনার, যাতে অ্যাডমিন প্যানেল, চুক্তি আর পোর্টালে একই শব্দ থাকে।)*
- **P6. প্রজেক্টের খরচের কাগজ:** চুক্তির ধারা ৩ অনুযায়ী কোনো প্রজেক্টের বিনিয়োগকারীরা সেই প্রজেক্টের খরচের তালিকা ও রিসিট পোর্টালে দেখতে পাবেন। অন্য বিনিয়োগকারীর নাম বা টাকা দেখবেন না। এটা ঠিক আছে? *(সুপারিশ: হ্যাঁ।)*

### 3.2 Before Phases 3–6

- **P7. নিজে নিবন্ধন:** invite লিংক দিয়ে যিনি আসবেন, তিনি পাসওয়ার্ড দিয়ে অ্যাকাউন্ট খুলবেন এবং NID-র দুই পাশ, ছবি ও নমিনির NID আপলোড করবেন। এরপর অ্যাডমিন যাচাই করে "Verified" করবেন। টাকা জমা আর কাগজের চুক্তি (স্ট্যাম্প, চেক, সাক্ষী) আগের মতো অফিসেই হবে। এটা ঠিক আছে?
- **P8. চুক্তি গ্রহণ:** পোর্টালে "আমি চুক্তি পড়েছি ও সম্মত" ক্লিক (সময়, IP ও ডিভাইস লগ সহ) কাগজের চুক্তির **পাশাপাশি** থাকবে (সুপারিশ), নাকি কাগজের **বদলে**? চুক্তির টেক্সট অ্যাডমিন প্যানেল থেকে বসানো হবে, তাই বর্তমান চুক্তিপত্রের টেক্সট দিতে হবে।
- **P9. টপ-আপ:** বিনিয়োগকারী একটা খোলা প্রজেক্ট বেছে নেবেন, টাকা পাঠাবেন এবং রেফারেন্স দেবেন। অ্যাডমিন টাকা পাওয়া নিশ্চিত করলে তবেই investment তৈরি হবে। এটা ঠিক আছে?
- **P10. NCC Bank:** BEFTN bulk upload-এর ফাইল ফরম্যাট লাগবে (ব্যাংকের টেমপ্লেট বা একটা স্যাম্পল ফাইল), সাথে কোম্পানির অ্যাকাউন্টের তথ্য। Host-to-Host-এর জন্য ব্যাংকের সাথে চুক্তি হয়েছে কি?
- **P11. মেসেজ:** লাভ জমা, পেমেন্ট, অনুরোধ অনুমোদন/বাতিল এবং KYC যাচাইয়ের SMS/WhatsApp মেসেজের লেখা বাংলা ও ইংরেজিতে দিতে হবে। WhatsApp-এর জন্য Meta-approved template-এর নাম লাগবে।

### 3.3 Before Phase 7 (period pool engine): the big decisions

- **D1. নতুন হিসাব কার জন্য:** পুরো কোম্পানির মাসিক/ত্রৈমাসিক লাভ "মূলধন × দিন" অনুপাতে ভাগ করার পদ্ধতি কি শুধু **নতুন চুক্তির জন্য আলাদা ধরন** হবে, আর বর্তমান প্রজেক্ট-ভিত্তিক চুক্তি যেমন আছে তেমন থাকবে? নাকি সব বিনিয়োগকারী এই পদ্ধতিতে চলে যাবেন? দ্বিতীয়টা হলে বর্তমান চুক্তিপত্র বদলাতে হবে (ধারা ৩-এ শুধু সরাসরি খরচ বাদ যায়)। *(সুপারিশ: আলাদা ধরন। পুরনো চুক্তি বদলাবে না।)*
- **D2. ভাগের হার:** ৩৫:৫৫:১০ কি নতুন পুল চুক্তির ডিফল্ট হবে, আর প্রজেক্ট চুক্তির ডিফল্ট ৪০:৫০:১০-ই থাকবে?
- **D3. কোম্পানির নিজের মূলধন:** Tasneem-এর ব্যবসায় কোম্পানির নিজের টাকাও খাটে। লাভ ভাগের আগে কোম্পানির নিজের মূলধনের অংশ কি আলাদা হবে, যেমন Project-01-এর ৯ লাখ gap-fill-এ হয়েছিল? *(সুপারিশ: হ্যাঁ।)* নাকি খসড়ার উদাহরণের মতো পুরো নিট লাভ শুধু বিনিয়োগকারীদের মূলধনের ওপর ভাগ হবে? প্রথমটা হলে প্রতি পিরিয়ডে কোম্পানির মূলধন কত ধরা হবে — অ্যাডমিন বসাবেন, নাকি হিসাব থেকে আসবে?
- **D4. নিট লাভে কী ঢুকবে:** সব Expense (ভাড়া, বেতন সহ), নাকি শুধু নির্দিষ্ট খরচের ক্যাটাগরি? Tasneem-এর সব বিক্রি, ক্রয়মূল্য ও খরচ কি ERP-তে পুরোপুরি ঢোকানো হয়? না হলে হিসাব ভুল আসবে। হিসাবরক্ষকের সাথে একবার মিলিয়ে নেওয়া দরকার।
- **D5. লোকসান:** কোনো পিরিয়ডে লোকসান হলে কোন নিয়ম চলবে? (ক) খসড়ার মতো সাথে সাথে মূলধন থেকে কাটা হবে, নাকি (খ) পরের পিরিয়ডের লাভ থেকে আগে লোকসান পূরণ হবে, তারপর লাভ ভাগ হবে? চুক্তিপত্রের ধারা ৪-এর নিয়ম (দুর্যোগে লোকসান বিনিয়োগকারী বহন করবেন, গাফলতিতে ম্যানেজার) কি পুলেও প্রযোজ্য?
- **D6. চ্যানেল পার্টনারের ১০%:** তিনি এটা পুরো মেয়াদ পাবেন, নাকি নির্দিষ্ট সময় পর্যন্ত? সরাসরি আসা বিনিয়োগকারীর ১০% কি প্রজেক্টের মতোই কোম্পানি পাবে?
- **D7. কোম্পানির তথ্য:** বিনিয়োগকারীরা কোম্পানির কতটুকু দেখবেন — শুধু নিট লাভ, নাকি আয় ও খরচের ক্যাটাগরি সহ?
- **D8. আনুমানিক লাভ:** চলতি পিরিয়ডের লাইভ আনুমানিক হিসাব দেখানো হবে কি? *(সুপারিশ: হ্যাঁ, "আনুমানিক — চূড়ান্ত নয়" লেবেল সহ, দিনে একবার আপডেট হবে।)* মূলধন কোন দিন থেকে গণনা হবে — টাকা জমার দিন থেকে?
- **D9. মেয়াদ ও lock-in:** পুল চুক্তির মেয়াদ কত, lock-in কত দিনের, আর আগে টাকা তুললে কী নিয়ম (চলতি পিরিয়ডের লাভ বাদ যাবে? জরিমানা?) — চুক্তির লিখিত নিয়ম লাগবে।
- **D10. উৎসে কর (TDS):** লাভ থেকে কর কি সিস্টেমেই কাটা হবে? হলে কোন হারে (TIN থাকলে এবং না থাকলে) — এই হার হিসাবরক্ষক দেবেন। আমি কোনো হার অনুমান করব না।

---

## 4. Technical decisions (my call; open to pushback)

### 4.1 Portal UI
A **third Filament panel** (`id: investor`, `path: investor`) with its own Vite theme (`resources/css/filament/investor/theme.css`) and the company's name and logo through `CompanySettingsService`, the same as the reseller panel. Filament already provides tables, stat widgets, charts, forms, a mobile layout and Livewire security, and the team already maintains one portal built this way. Mobile-first means: card layouts for investments and payouts, tables only where a list is long, and every page checked at 360 px width.

### 4.2 Host routing
- New `companies.investor_portal_enabled` (bool, default false) and `companies.investor_portal_domain` (nullable, unique, normalised with `Company::normalizeDomain()`), edited in Company Management by a super admin.
- New `Company::forInvestorPortalHost($host)`: returns the custom domain's company, or `investor.` + `companies.domain` when no custom domain is set, and only when the portal is enabled for that company.
- `RestrictResellerPortalHost` becomes `RestrictPortalHosts`, which reads a small registry: `reseller` → path `portal`, `investor` → path `investor`. The rule stays the same: on a portal host only that portal's path (plus the existing pass-through list) is served, and that portal's path returns 404 on every other host. Every existing reseller-portal test must pass unchanged.
- New `ResolveCompanyFromInvestorPortalDomain`, registered as panel middleware **and** persistent middleware, so Livewire update requests resolve the company too. This mirrors the bug already fixed on the reseller portal.

### 4.3 Who logs in: `investor_portal_accounts`
A new table and an `InvestorPortalAccount` model (Authenticatable, `FilamentUser`, `HasName`) behind a new guard `investor` with provider `investor_portal_accounts` in `config/auth.php`.

- One account per phone per company. It can link to an `investor_id`, a `channel_partner_id`, or both. The draft says "referrers use the same panel", and one person can be both an investor and a partner, so one login shows both roles.
- Login, OTP and invite data stay out of `Investor` and `ChannelPartner`, which the admin side, the import queue and the reports use heavily.
- `canAccessPanel()`: the panel id is `investor`, the account's company is the host's company, the portal is enabled, and the status is `pending` or `active`. A `pending` account sees only the status/KYC page. `disabled` and `rejected` accounts cannot sign in. This is re-checked on every request, so disabling an account locks the person out at once.
- Pages declare their role with `canAccess()`: investor pages need a linked investor, partner pages need a linked active partner.

### 4.4 Login, OTP and password reset
- A login page with two modes: phone + password, and phone + SMS code.
- The OTP mechanics (hashed code, 10-minute expiry, 5 tries, one send per minute) are moved out of `CustomerAccountService` into a small shared `App\Support\OneTimeCode`, used by both. All existing customer-login tests must stay green.
- SMS is sent with `StorefrontNotificationService::sendSms()` using `StorefrontSetting::primaryFor($company->id)` (the `CLAUDE.md` rule for company-level messaging). If SMS is not configured, the OTP mode is hidden and password login still works.
- Password reset uses an SMS code on one page, like the reseller `ForgotPassword`. Phones are normalised with `StorefrontApi\Phone` before any lookup. Login, OTP and reset routes get the same `throttle` limits as the storefront login.

### 4.5 Data scoping
- No global scope is added to `Investment`, `SettlementPayout` and the other shared models, because the admin side uses them unscoped. Instead every portal page, widget and download builds its query from one helper, `InvestorPortal::for($account)`, which pins `investor_id` or `channel_partner_id`. The host company is already enforced by `CompanyScope` through `CompanyContext`.
- Downloads go through a portal controller that checks ownership: the document belongs to this investor/partner, or it is the contract or cost receipt of a project this investor has money in (P6).
- Never shown in the portal: the project register (it has every investor's NID and phone), other investors' payouts, staff notes, or cheque and stamp numbers other than the investor's own.
- Tests prove that every page and route returns 404 for another investor's or partner's id.

### 4.6 Statement sub-ledger: `investor_ledger_entries`
An append-only statement per investor and per partner. It is separate from the accounting `TransactionLedger`, and the UI calls it "Statement" to avoid confusion.

- **Columns:** `company_id`, holder (morph: `Investor` or `ChannelPartner`), `investment_id`, `project_id`, `entry_type` (`deposit`, `profit`, `partner_commission`, `payout`, `withdrawal`, `reinvest_out`, `reinvest_in`, `loss`, `adjustment`, `tax_withheld`), signed `amount` (decimal 15,2), `bucket` (`capital` or `profit`), `entry_date`, source (morph), `note`, `created_by`. Unique on (source type, source id, entry type), so writing an entry twice has no effect.
- **Model guards:** rows are never updated or deleted. A correction is a new `adjustment` row with a reason.
- **One writer, `InvestorStatementService`, called from the existing flows:**
  - investment created → `deposit`
  - settlement confirmed → `profit` per investor and `partner_commission` per partner
  - payout marked paid → `payout`
  - payout made unpaid by a trashed voucher, or paid again on restore → an `adjustment` pair
  - investment withdrawn (or its voucher voided) → `withdrawal` (or its reversal)
  - reinvest election → `reinvest_out` from profit and `reinvest_in` on the new investment
  - pool period locked (Phase 7) → `profit` or `loss`
- **Backfill:** `php artisan investors:rebuild-statement {--company=}` loops per company (a `CLAUDE.md` rule), is idempotent, and is run once on deploy.
- **Reconciliation:** `investors:check-statement` compares the statement totals with the source tables and reports any difference. A test covers it.

### 4.7 Money math in the new engine
The project settlement keeps its current float math, which is proven against the signed settlement sheet; it is not changed. The pool engine (Phase 7) works in **integer paisa with `Brick\Math\BigInteger`**. Brick is already installed as a Laravel dependency; it will be required explicitly in `composer.json`. Reason: net profit × capital × days overflows a 64-bit integer at the scale of crores. For example ৳5 crore capital × 92 days × ৳40 lakh net, all in paisa, is about 1.8 × 10²⁰.

### 4.8 Documents
Printable HTML views in `resources/views/investor-portal/documents/`, reusing the style partials of `investments/reports/` and the company logo:
- the period or date-range statement
- the investment certificate
- the agreement copy with an acceptance stamp (accepted at, IP, version, content hash)

The existing per-investor payout report is reused through a portal route that checks ownership. The investor saves any of these as PDF from the browser, or through the Android app's print bridge.

### 4.9 Audit
- A migration adds nullable `actor_type` and `actor_id` to `audit_logs`.
- `AuditLogService` keeps writing `user_id` for staff, and records any other authenticated model (`InvestorPortalAccount`, `Customer`) in the actor columns. The Audit Log resource shows it.
- These portal actions are audited: login success and failure, OTP sends, agreement acceptance, KYC upload, bank account submit, request create/cancel, password change.
- These admin actions are audited: KYC decisions, bank approvals, request decisions, batch create/approve/export/result, period calculate/approve/lock.

### 4.10 Notifications
- `InvestorNotificationService` sends SMS plus WhatsApp templates as a queued job, which sets and clears `CompanyContext` (a `CLAUDE.md` rule).
- Message texts are admin-managed per company and per event, in Bangla and English, on a new **Investor Portal Settings** page. No message text is hardcoded.
- A failed message never blocks the money action. It is logged and shown on the record.

### 4.11 Language
Every string goes through `__()`, with keys in both `lang/en.json` and `lang/bn.json` in the same commit (`TranslationParityTest`). The account stores a `locale`. The portal uses whichever locale mechanism the admin and reseller panels use at build time (see `docs/i18n-bangla-english-plan.md`). Admin-entered text (agreement body, messages) is entered per language and is not put in the lang files.

---

## 5. Data model

All migrations are additive and forward-only. None of them is run on the demo database outside the test suite. Every new company-owned model uses `BelongsToCompany` + `CompanyScope`, is added to `MultiCompanyIsolationTest::test_every_company_owned_model_uses_the_company_scope_contract`, and is registered with `AuditObserver`.

| Phase | Table / change | Main columns and rules |
|---|---|---|
| 1 | `companies` + | `investor_portal_enabled` (bool, false), `investor_portal_domain` (nullable, unique) |
| 1 | **`investor_portal_accounts`** | `company_id`, `investor_id` (nullable, unique), `channel_partner_id` (nullable, unique), `phone` (unique per company), `password` (nullable until set), `status` (`invited`, `pending`, `active`, `disabled`, `rejected`), `otp_code_hash`, `otp_channel`, `otp_expires_at`, `otp_sent_at`, `otp_attempts`, `invite_token_hash`, `invite_expires_at`, `last_login_at`, `last_login_ip`, `locale`, `remember_token`. At least one of investor/partner must be set, except while the account is a `pending` applicant. |
| 1 | `audit_logs` + | `actor_type`, `actor_id` (nullable) |
| 2 | **`investor_ledger_entries`** | As in §4.6 |
| 3 | `investors` + / `channel_partners` + | `kyc_status` (`pending`, `verified`, `rejected`), `kyc_verified_at`, `kyc_verified_by`, `kyc_rejection_reason`. Existing rows are backfilled as `verified`, because their paper KYC is already done. "Active" is shown, not stored: verified and holding at least one active investment. Partners also get `invite_code` (unique per company) and `invite_enabled`. |
| 3 | **`investor_bank_accounts`** | `company_id`, holder (morph: `Investor` or `ChannelPartner`), `type` (`bank`, `mfs`), `bank_name`, `branch_name`, `routing_number` (9 digits, BEFTN), `account_name`, `account_number` (encrypted) + `account_last4`, `mfs_provider`, `mfs_number`, `status` (`pending`, `approved`, `rejected`, `replaced`), `is_default`, `approved_by/at`, `rejection_reason`, `source` (`admin`, `portal`). Backfilled from `channel_partners`' inline bank fields as `approved`. A payout still copies the recipient into `settlement_payouts.recipient_*` at payout time, as a snapshot. |
| 3 | `investor_drafts` + | `source = portal`, `portal_account_id`. KYC files attach to the draft through `investment_documents` and move to the investor on confirm. |
| 3 | `investment_documents` categories + | `investor_photo`, `partner_photo` |
| 3 | **`agreement_templates`** | `company_id`, `kind` (`investor_deed`, `partner_agreement`, `pool_contract`), `version`, `locale`, `title`, `body`, `published_at`, `published_by`. Immutable once published; a change is a new version. |
| 3 | **`agreement_acceptances`** | `company_id`, `agreement_template_id`, `portal_account_id`, `investor_id`/`channel_partner_id`, `investment_id` (nullable), `accepted_at`, `ip_address`, `user_agent`, `body_sha256`. Immutable. |
| 4 | **`investor_requests`** | `company_id`, `portal_account_id`, `investor_id`/`channel_partner_id`, `type` (`top_up`, `withdrawal`, `reinvest_election`, `bank_change`, `support`), `investment_id`/`project_id`, `amount`, `payload` (json), `message`, `status` (`pending`, `approved`, `rejected`, `cancelled`, `completed`), `admin_note`, `decided_by/at`, result (morph). Only one pending request per investor, type and investment. |
| 5 | **`payout_batches`** | `company_id`, `batch_number` (sequential), `kind` (`investor`, later `reseller`), `status` (`draft`, `approved`, `exported`, `completed`, `cancelled`), `payout_date`, `fund_source_id`, `file_format` (`ncc_beftn`, `manual_list`), `created_by`, `approved_by` (must differ from `created_by`), `approved_at`, `exported_at`, `export_path` (private) + `export_sha256`, `total_amount`, `item_count`, `voucher_id`, `notes` |
| 5 | **`payout_batch_items`** | `company_id`, `payout_batch_id`, payable (morph: `SettlementPayout`, `ChannelPartnerPayout`, `ProfitDistribution`, later `ResellerPayoutRequest`), payee snapshot (name, bank, branch, routing, account number encrypted, MFS), `amount`, `status` (`pending`, `paid`, `failed`), `bank_reference`, `failure_reason`, `paid_at`. A payable can be in only one live batch. |
| 7 | `investment_projects` + | `kind` (`deal` default, `pool`), `payout_frequency` (`monthly`, `quarterly`; pools only) |
| 7 | `investments` + | `investor_share_percent`, `channel_partner_share_percent`, `company_share_percent` (nullable per-contract overrides; must still total 100), `tenure_months`, `lock_in_until`, `profit_option` (`payout`, `reinvest`), `partner_share_ends_at` (D6). `status` gains `matured` and `closed`. |
| 7 | **`profit_periods`** | `company_id`, `project_id` (the pool), `period_start`, `period_end` (unique per pool), `status` (`draft`, `calculated`, `locked`), `revenue`, `cogs`, `order_costs`, `expenses`, `manual_lines_total`, `net_profit`, `company_capital_weight` (D3), `investor_weight_total`, `outcome` (`profit`, `loss_investor_borne`, `loss_manager_borne`), `loss_reason`, `snapshot` (json), `calculated_by/at`, `approved_by/at` (must differ), `locked_at` |
| 7 | **`profit_period_lines`** | Manual P&L lines entered before lock: `label`, `amount`, `reason`, `created_by` |
| 7 | **`profit_distributions`** | Per investment and period: `capital_segments` (json), `days`, `weight` (string, big integer), `pool_amount`, `investor_amount`, `partner_amount`, `company_amount`, split snapshot, `profit_option` snapshot, `payment_status` (`pending`, `reinvested`, `in_batch`, `paid`), `tax_withheld` (0 until D10) |
| 7 | `channel_partner_payouts` + | `profit_period_id` (nullable); `settlement_id` becomes nullable; exactly one of the two must be set |

---

## 6. Portal pages

### 6.1 Investor

| # | Page | Content | Phase |
|---|---|---|---|
| 1 | **Dashboard** | Cards: active capital, total profit received, profit confirmed but not yet paid, current balance. A card per investment: project, amount, start date, days running, status, last result (rate per lac and annualized %), and next expected settlement or payout date. A 12-month profit chart built from the statement. The last 5 statement rows. In Phase 7, an estimated-profit card with a clear label (D8). | 2 |
| 2 | **My investments** | List and detail: the project (deal reference, trade cycle, window, status), its cost sheet and receipts (P6), the settlement result (net profit, investor pool, rate per lac, annualized, my share), own security cheque number, election and notice status, documents. | 2 |
| 3 | **Statement** | The statement with a date filter, plus a printable statement. | 2 |
| 4 | **Payouts** | Each payout: amount, paid date, bank reference, and a link to the payout report. | 2 |
| 5 | **Documents** | Own NID and photo copies, project contracts, signed contracts, payout reports, certificates, the agreement acceptance copy. | 2–3 |
| 6 | **Requests** | Own requests with their status, and a "New request" form: top-up, withdrawal notice, reinvest choice, bank change, support. | 4 |
| 7 | **Bank accounts** | Approved and pending accounts. Adding one creates a pending account that the admin must approve. | 3 |
| 8 | **Profile** | Name, phone and NID as read-only; nominee as view-only (changes go through a support request); email; password; language. | 1 |
| 9 | **Company performance** | Per D7. | 7 |

### 6.2 Channel partner

| # | Page | Content | Phase |
|---|---|---|---|
| 10 | **My investors** | As decided in P4: name or pseudonym, active capital, date joined. | 2 |
| 11 | **Commissions** | Payouts per settlement or period, with paid and due totals and a chart. | 2 |
| 12 | **Invite** | The invite link and code with Copy and WhatsApp share buttons; the partner's applicants and their status. | 3 |

### 6.3 Applicant (pending account)
A status page only: KYC uploads, the agreement to accept (P8), the current review status, and the rejection reason if rejected.

---

## 7. Admin side

1. **Investor Portal Settings** page in the Investments cluster: portal on/off, custom domain (super admin), invite link expiry, agreement versions (create/publish), notification texts per event and language, WhatsApp template names.
2. **`InvestorResource` / `ChannelPartnerResource`:** a "Portal access" section (status, invite by SMS, disable, re-invite), KYC Verify/Reject actions (a reason is required on reject), and a Bank accounts relation manager with Approve/Reject.
3. **New resources:**
   - **Investor Requests:** a queue with a pending-count badge. Approve and reject actions call the existing services:
     - withdrawal → `InvestmentWithdrawalNotice` (60 days), then `InvestmentWithdrawalService` on the effective date
     - reinvest → `CycleElectionService::recordElection()`
     - top-up → a new `Investment` once the money is confirmed
     - bank change → approves the `investor_bank_accounts` row
   - **Payout Batches** (Phase 5).
   - **Profit Periods** (Phase 7).
4. **New permissions** (`User::CUSTOM_PERMISSION_OPTIONS` + `ROLE_PERMISSIONS`, documented in `docs/roles-and-permissions.md`):
   - `investments.portal_manage`: accounts, invites, KYC, bank approval, requests
   - `investments.payout_batch_create`
   - `investments.payout_batch_approve`
   - `investments.period_calculate`
   - `investments.period_approve`

   Maker-checker is enforced in the service: the approver must be a different user from the maker, **even for a super admin**.
5. Portal applications appear in **Investor Drafts** with source "Portal" and their KYC files, and go through the existing confirm flow.

---

## 8. Period pool engine (Phase 7, only after D1–D10)

A pool is an `InvestmentProject` with `kind = pool`. It has no end date, it uses monthly or quarterly periods, and investments join it with the existing machinery: documents, security cheque, witnesses, withdrawal, vouchers and the investor draft queue. Deal projects and their settlement are untouched.

**Step 1: P&L snapshot** (`PeriodProfitCalculator`, company-scoped, period [s, e])
- Revenue = Σ order-item subtotal of orders in `Order::ACCOUNTED_STATUSES` with `order_date` in [s, e]. This is the same basis as `ReportService::profitForExport()`.
- COGS = Σ `unit_cost` × quantity on the same items.
- Order costs = Σ `OrderCost` on those orders. `HEAD_PURCHASE` is checked against COGS so purchase cost is not counted twice. The final list of heads is agreed with the accountant (D4).
- Expenses = Σ `Expense` in [s, e], restricted to the expense categories chosen for the pool (D4).
- Manual lines (a reason is required) for anything not in the ERP.
- Every line and total is frozen in `snapshot`. Editing an order later does not change a locked period; the difference is entered as a manual line in the next period.

**Step 2: weights.** Each investment's capital over the period is split into segments wherever its capital balance changes (top-up, reinvested profit, partial withdrawal), taken from the statement. Days are counted inclusively from the value date (D8) up to the day before exit.
- weight per investment = Σ (segment capital in paisa × segment days)
- investor weight W_inv = Σ weight over all investments
- company weight W_co = the company's own capital × days (D3); 0 if the owner chooses the draft's rule

**Step 3: pool.**
- pool per investment = ⌊net × weight ÷ (W_inv + W_co)⌋
- The remainder (the company's capital slice plus rounding) goes to the company.

**Step 4: three-way split.** Each split is stored in basis points: 3500 / 1000 / 5500 by default, or the contract's override.
- investor = ⌊pool × investor bp ÷ 10000⌋
- partner = ⌊pool × partner bp ÷ 10000⌋, but only if the investment has a partner and is still within the partner's tenure (D6); otherwise 0 and that part goes to the company
- company = pool − investor − partner

Every remainder goes to the company, so the investor, partner and company totals always add up to the locked net profit exactly (the draft's rounding rule).

**Step 5: loss** (net < 0). Like the project settlement, an outcome and a reason are required.
- `loss_investor_borne`: each investment's share of the loss, in proportion to its weight, is posted as a `loss` entry on capital. The partner gets 0.
- `loss_manager_borne`: investors bear nothing and the company bears all of it.
- If D5 chooses carry-forward, the loss is held and set off against the next profits before any split. The schema leaves room for this, so no redesign is needed.

**Step 6: maker-checker and lock.**
- `calculate()` by user A sets the status to `calculated`. Recalculating before approval replaces the distributions.
- `approve()` by user B (B ≠ A, permission `investments.period_approve`) locks the period.
- A locked period and its distributions are immutable (model guards, like `ProjectSettlement`). A mistake found after lock is corrected with an adjustment in the next period (the draft's rule).

**Step 7: posting on lock.**
- Statement `profit` entries are written. Where `profit_option = reinvest`, a `reinvest_in` capital entry is written instead, effective from e + 1.
- Partner amounts are grouped into `channel_partner_payouts` (one per partner per period).
- A draft payout batch is created for the payout-option distributions and the partner payouts.
- Notifications are sent.

**Estimated profit** (D8): the same calculator runs on [s, today]. Nothing is stored. The result is cached per company and refreshed daily, and it is always labelled as an estimate.

**Required tests:**
- The draft's worked example: net ৳4,00,000; Rahim ৳5 lakh → 14,000 / 4,000 / 22,000; Karim ৳20 lakh → 56,000 / 16,000 / 88,000; totals 1,40,000 / 40,000 / 2,20,000.
- A mid-period joiner.
- A top-up and a reinvest inside a period (segments).
- Rounding (three equal investors and an indivisible net); totals must equal net exactly.
- The company-capital slice (D3).
- An investor with no partner (their 10% goes to the company).
- A partner whose tenure has ended.
- Both loss outcomes.
- The maker approving their own calculation is refused, even for a super admin.
- A locked period cannot be edited.
- Posting twice has no effect.
- Company isolation.

---

## 9. Payout batches (Phase 5)

1. **Create** (maker, `investments.payout_batch_create`). Pick the payables: pending investor payouts on confirmed settlements, pending partner payouts, and, in Phase 7, payout-option distributions. Each item copies the payee's approved default bank account. Payables without an approved account are listed separately and left out.
2. **Approve** (checker, a different user). The items are locked.
3. **Export.** `BankPayoutFileFormat` is an interface:
   - `NccBeftnFormat` is built strictly from NCC Bank's template (P10), with a test against the bank's sample file.
   - `ManualListFormat` is a printable list for MFS or cash payees.

   The file is stored privately, its SHA-256 is recorded, and every download is audited.
4. **Results.** Mark all items paid with the bank reference, or mark single items failed with a reason. A failed item becomes pending again and can join a new batch.
5. **Booking.**
   - One pending `investor_payout` debit voucher is created per batch through `VoucherService::submit()`.
   - Every paid payable gets that `voucher_id`, so the existing `VoucherLinkedPayoutsService` trash/restore works for the whole batch with no change.
   - The single-row "Mark as Paid" action and the batch both go through one new `PayoutCompletionService`, so the voucher, the statement entry and the settlement's `paid_out` status are handled in one place.
6. **Reseller withdrawals** can use the same tables later (`kind = reseller`, voucher type `business_expense`, as `ResellerPayoutService` books today). This is optional and not needed for the investor portal.
7. **Phase 5b, NCC Host-to-Host:** only after the bank onboards the company and provides the endpoint, SFTP/API details, keys and IP whitelisting. The credentials are encrypted, admin-configurable company settings (`CLAUDE.md`: the owner plugs in the keys). A queued job uploads the approved file and reads the bank's status back.

---

## 10. Phases

Each phase is one commit, made only with the owner's approval. Each commit includes a CHANGELOG entry (`docs/release-policy.md`), an `UPDATE_NOTES.md` entry, both lang files and tests, and runs the affected tests plus the full `php artisan test` (plain, **no `--env`**), and `npm run build` when the theme changes. Each phase can go live on its own.

| Phase | Content | Depends on | Release |
|---|---|---|---|
| **1. Portal foundation** | Company columns; `RestrictPortalHosts` refactor; `ResolveCompanyFromInvestorPortalDomain`; `investor` guard and panel; `InvestorPortalAccount`; password + OTP login; SMS password reset; invite by SMS (staff invites existing investors and partners); applicant status page; Profile page; audit actor columns; Investor Portal Settings (on/off, domain, invite expiry) | P1–P3 | Minor |
| **2. Statement and read-only views** | `investor_ledger_entries` + `InvestorStatementService` + hooks in existing services + backfill and reconciliation commands; pages 1–5 and 10–11; printable statement and certificate; portal document download with ownership checks | 1, P4–P6 | Minor |
| **3. Onboarding and KYC** | Partner invite links and codes; self-registration → `InvestorDraft` (source portal); KYC uploads; Verify/Reject; `investor_bank_accounts` (pages 7 and 12); agreement templates + acceptance | 1, P7–P8 | Minor |
| **4. Requests** | `investor_requests`; page 6; the admin queue wired to existing services; in-app status | 2, 3, P9 | Minor |
| **5. Payout batches** | §9 steps 1–6; `PayoutCompletionService`; NCC BEFTN export once the template arrives (until then only the manual list) | 2, 3, P10 | Minor |
| **5b. NCC H2H** | §9 step 7 | 5 + bank onboarding | Minor |
| **6. Notifications** | `InvestorNotificationService`; admin-managed SMS/WhatsApp texts; events: invite, KYC decision, profit credited, payout paid, request decided | 1, P11 | Minor |
| **7. Pool engine** | §8 in full; pages 9 and the estimate card; Profit Periods resource | D1–D10, 2, 5 | Minor (Major if D1 moves existing investors) |
| **8. Go-live** | DNS and Coolify steps in `docs/deployment.md`; an owner guide in Bangla; `PROJECT_GUIDE.md` investor-portal section; `ERP_PHASE_ROADMAP.md` Phase 15 "Investor-facing portal" marked done; a security review of the portal (`/security-review`) | all | Maintenance |

Phase order versus the draft: the draft builds the calculation engine second. Here the portal comes first because it is useful straight away on today's real data (Project-01) and needs no new rules. The pool engine waits for D1–D10. If those answers arrive early, Phase 7 can move up to right after Phase 2.

---

## 11. Tests (new files)

| File | Covers |
|---|---|
| `InvestorPortalHostTest` | The portal only on its host; 404 on admin, shop and reseller hosts; the reseller portal still works; Livewire requests resolve the company |
| `InvestorPortalAuthTest` | Password login, OTP (expiry, 5 tries, resend limit), throttle, SMS reset, invite link (expired, used), a disabled account locked out at once, company A's account refused on company B's host |
| `InvestorPortalScopingTest` | Every page and download returns 404 for another investor's or partner's ids; the project register is never reachable |
| `InvestorStatementTest` | Each hook writes exactly one entry; voucher trash/restore pairs; backfill is idempotent; reconciliation matches the source tables |
| `InvestorOnboardingTest` | Invite code → application → draft → confirm → active account; KYC files move to the investor; rejection keeps the reason; agreement acceptance is immutable with hash, IP and time |
| `InvestorRequestTest` | Each request type → the right service; one pending request per type; cancel; audit |
| `PayoutBatchTest` | Maker ≠ checker; one live batch per payable; one voucher per batch; voucher trash unpays every item; failed item back to pending; export hash; NCC format against the bank's sample |
| `InvestorPoolEngineTest` | §8 test list |
| `InvestorNotificationTest` | Texts from settings in both languages; a failed send does not block the action |
| Existing | `MultiCompanyIsolationTest` (every new model), `TranslationParityTest`, reseller portal tests (unchanged), `InvestmentSettlementTest` (unchanged) |

---

## 12. What the owner does on the server (Phase 1 go-live)

1. **DNS:** add an `A` (or `CNAME`) record `investor` → the same server as `tasneemknitindustry.com`. For a separate domain, point that domain at the server.
2. **Coolify:** add `https://investor.tasneemknitindustry.com` to the app's **Domains** so Traefik issues its certificate.
3. **Admin:** Company Management → Tasneem → turn the investor portal on (and set the custom domain only if one is used).
4. **SMS:** check that Tasneem's storefront settings have a working SMS gateway (P2).
5. No new environment variables. `SESSION_DOMAIN` stays empty, so the portal login stays separate from the admin and shop logins.

---

## 13. Risks

| Risk | Mitigation |
|---|---|
| The pool engine is built on rules that differ from the signed contracts | Phase 7 is blocked until D1–D10 are answered in writing; deal contracts are never moved silently |
| The ERP does not hold Tasneem's full books, so the period P&L is wrong | D4; the snapshot shows every line before lock; manual lines need a reason; maker-checker |
| Another investor's PII leaks through the portal | One scoping helper; ownership-checked downloads; the 404 test suite; the register is never exposed |
| The statement drifts from the source records | One writer service, unique source keys, the reconciliation command and test |
| The host-guard refactor breaks the live reseller portal | The registry keeps the reseller rule identical; all reseller tests must pass unchanged |
| A wrong bank file pays the wrong account | Only approved bank accounts; snapshot at batch time; maker-checker; file hash; built only from the bank's template |
| An SMS gateway outage blocks login | Password login is always available; OTP is an extra option |
| Integer overflow in profit math | `Brick\Math` in the pool engine (§4.7) |

---

## 14. Docs to update along the way

- `PROJECT_GUIDE.md`: an "Investor Portal" section (Phases 1, 2 and 8).
- `ERP_PHASE_ROADMAP.md`: Phase 15 "Investor-facing portal" (Phase 8).
- `docs/roles-and-permissions.md`: the new permissions (Phases 4, 5 and 7).
- `docs/deployment.md`: the DNS and Coolify steps (Phase 1).
- `CLAUDE.md`: a short "Investor portal" rules block once P1–P11 are answered, so later agents follow the same decisions.
- This file: record each owner answer next to its question, as `13_RESELLER_PORTAL_PLAN.md` does.

---

## 15. Build log

### 2026-10-07 — Phases 1 and 2 built

The owner asked to implement the plan ("ইমপ্লিমেন্ট কর"). Phases 1–2 need no new business rule, so they were built with the recommended answers to P1–P6 (shown below). Phases 3–7 still need the owner's material and answers (agreement text, NCC file format, message texts, D1–D10) and were not started.

**Answers assumed (owner to confirm or change):**
- P1: `investor.<company domain>/investor`, plus an optional custom domain (Super Admin).
- P2: password and SMS code both; the SMS option appears only while the company's storefront SMS gateway is set (`StorefrontSetting::primaryFor()`).
- P3: staff press **Portal access → Invite**; the SMS link (valid 7 days) lets the person set a password. If SMS is not set, staff see the link and share it by hand.
- P4: a partner sees each investor's report name (pseudonym if set), capital at work and join date only.
- P5: "Channel partner".
- P6: an investor downloads their project's contract, cost receipts and purchase documents; settlement sheets, investor reports and bank slips (which can name other investors) are not shown. The payout report (own figures + the project's cost sheet) is shown.

**Built differently from §4–§5, and why:**
- **Statement (§4.6):** built from the records on every view (`InvestorPortalService::statement()`), not stored in `investor_ledger_entries`. The project flows have several staff-side corrections (void, voucher trash/restore, undo election); reading the records directly means the statement can never drift from them, and no hooks were added to the settlement code. The stored sub-ledger is still needed for the pool engine (Phase 7) and is built then.
- **No running balance column:** whether a reinvested payout is also marked paid is up to staff today, so a running "balance" could double-count. The statement lists events (in, profit/loss, paid out, reinvested) and the summary cards show totals that do not depend on that.
- **Account statuses:** `invited`, `active`, `disabled`. `pending` and `rejected` (self-registration) come with Phase 3.
- **One-time codes:** written in `InvestorPortalAccountService` with the same rules as `CustomerAccountService` (hashed, 10 minutes, 5 tries, one send per minute). The storefront code was not moved into a shared class, to leave live customer login untouched.
- **Language and invite expiry:** the app has no per-user language switch yet, so the portal follows the app language; `locale` is stored on the account for later. Invite expiry is a fixed 7 days (`INVITE_VALID_DAYS`), not a setting.
- **Permission:** `investments.portal_manage` (settings page and Portal access actions). Only Super Admin holds it by default; give it to a custom role if someone else should manage portal access.
- **Host guard:** `RestrictResellerPortalHost` was renamed `RestrictPortalHosts` and handles both portals: the portal that owns a host gets every path, and every portal path is a 404 on other hosts. All reseller portal tests pass unchanged.
- **Audit:** `audit_logs.actor_type` / `actor_id` record a portal user (investor account or reseller). Portal sign-ins, failed sign-ins, code sends, invites, password changes and document downloads are logged.

### 2026-10-08 — Phases 3 and 4 built

Owner: "পরবর্তি কাজ কর". Built from the rules in the owner's draft (§1.2: sign-up through a referrer's link with admin verification; agreement accepted in the portal with time and IP; requests top-up / withdrawal / reinvest / support, pending → approved / rejected). Answers assumed: P7, P8 (beside the paper deed), P9 as recommended in §3.2.

**Built differently from §5, and why:**
- **No `kyc_status` columns.** A portal applicant is reviewed in the existing Investor Drafts queue; confirming the draft is the verification, and the account's status (`pending` / `active` / `rejected`) is what the applicant sees. Every existing investor already has paper KYC. One review place instead of two.
- **No `partner_photo` category** and partners do not self-register (the draft only describes investor sign-up); partners are still created by staff.
- **Partner bank details not backfilled** into `investor_bank_accounts`: the partner record's own payout fields keep working as today; a partner (or staff) adds a payment account when needed. Payouts still copy the recipient by hand until Phase 5 uses the approved default account.
- **Agreement templates have no `locale`:** staff paste the text in the language the deed is written in.
- **Bank change** is not a separate request type: adding a payment account is the request, approved on the person's page.
- **Requests** need the permission of the flow they run: top-up and withdrawal `investments.manage`, payout choice `investments.settle`, questions `investments.portal_manage`. No maker-checker here (that is for payout batches and pool periods).

### 2026-10-08 — Phase 5 built (payout batches, without the NCC file)

Owner: "পরবর্তি কাজ কর". §9 steps 1–5 are built; the NCC BEFTN export (step 3) waits for the bank's template (P10) and is never guessed, so the only export is the CSV payment list. Step 6 (reseller withdrawals) and Phase 5b (H2H) are not built.

**Built differently from §5 / §9, and why:**
- **Payouts with a reinvest choice are left out** of batches (listed with the reason). Today a reinvested payout is still marked paid with a voucher for the full amount while the reinvestment books its own capital voucher; sending the full amount by bank would be wrong and sending less would break that accounting. They stay on the settlement page's single-row flow until the owner decides how a batch should treat them.
- **One voucher per batch from one fund source.** The batch has a fund source; left empty, the projects' payout fund source is used when every payout shares one, otherwise no voucher is booked (the same rule as a project without a payout fund source today). A voucher failure stops the recording, so a batch is never marked paid without its voucher when a fund source is set.
- **Statuses** `draft → approved → exported → completed`, or `cancelled` from any of the first three. Recording paid is allowed from `approved` too (an MFS batch paid by hand needs no file). Items are `pending / paid / failed`; a failed item or a cancelled batch frees the payout.
- **Who does what:** `investments.payout_batch_create` makes the batch, takes items out, downloads the list, marks failures and records the result; `investments.payout_batch_approve` approves (never the maker). Either permission can cancel a live batch. Only Super Admin holds them by default.
- **No `ProfitDistribution` payables** yet (Phase 7).
- **Single-row Mark as Paid** now goes through `PayoutCompletionService` and refuses a payout held by a live batch; `CycleElectionService` refuses a reinvest on such a payout. Restoring a trashed batch voucher does not re-mark a payout that has meanwhile joined a new live batch.

