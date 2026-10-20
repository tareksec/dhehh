# IMS v2 — TRD (Technical Requirements Document)

> PRD-র প্রতিটা চাহিদা কোডে কীভাবে হবে: ডেটা, সার্ভিস, নিয়ম, জব, নিরাপত্তা, টেস্ট।
> সিস্টেমের গঠন ও ডোমেন রাউটিং: `ARCHITECTURE.md`। অনুমতি: `ROLES.md`।
> Status: **প্ল্যান। কোনো কোড শুরু হয়নি।** নাম ও কলাম প্রস্তাবিত; বানানোর সময় সামান্য বদলাতে পারে, কিন্তু নিয়ম বদলাবে না।

---

## ১. প্রযুক্তি (এখনকার ERP, বদলাবে না)

| জিনিস | যা আছে |
|---|---|
| ভাষা ও ফ্রেমওয়ার্ক | PHP 8.4, Laravel 13, Filament 5 (Livewire) |
| ডেটাবেস | MySQL 8.4 (প্রোডাকশন), SQLite (ডেমো/টেস্ট) |
| ক্যাশ/কিউ/সেশন | Redis (প্রস্তাবিত, `docs/deployment.md`), কিউ ওয়ার্কার |
| PDF | `barryvdh/laravel-dompdf`, Hind Siliguri (নিজস্ব হোস্টেড) |
| Excel/CSV | `openspout/openspout` |
| ফাইল | প্রাইভেট ডিস্ক (লোকাল বা Cloudflare R2, `docs/deployment.md`) |
| হোস্টিং | VPS → Coolify → Traefik → একটাই Laravel কন্টেইনার |
| SMS | এখনকার কোম্পানি-ভিত্তিক SMS সেটিং (`SmsTemplateService`) |

নতুন প্যাকেজ যত কম সম্ভব। শুধু KYC-র OCR-এর জন্য একটা লাগবে (§৭)।

## ২. রিপোর নিয়ম যা প্রতিটা কাজে মানতে হবে (`CLAUDE.md`)

- নতুন প্রতিটা কোম্পানি-মালিকানার মডেল: `BelongsToCompany` + `CompanyScope`, আর `MultiCompanyIsolationTest`-এ যোগ।
- প্রতিটা লেখা `__('English')`, `lang/en.json` ও `lang/bn.json` দুটোতেই (`TranslationParityTest`)।
- নতুন সাইডবার ব্যাজ `HasGuardedNavigationBadge`, উইজেট `GuardsWidgetRendering`, বাকি সব-পেজের জিনিস `ModuleGuard::run()`।
- মাইগ্রেশন MySQL 8.4-এ পাস; লম্বা ইনডেক্সের নাম নিজে দেওয়া (৬৪ অক্ষর সীমা)।
- কিউ জব `CompanyContext` সেট/ক্লিয়ার করে; শিডিউল কমান্ড প্রতি কোম্পানি লুপ।
- টেস্ট শুধু `php artisan test` (কোনো `--env` নয়); ডেমো ডেটা কখনো ছোঁয়া নয়।
- প্রতিটা কমিটে `CHANGELOG.md` + `UPDATE_NOTES.md`; ব্রাঞ্চ `feature/ims-v2`; `main` শুধু মালিকের অনুমতিতে।

## ৩. ডেটা মডেল

### ৩.১ যা আছে, রাখা হবে

`investors`, `investor_portal_accounts`, `investor_bank_accounts`, `investor_drafts`, `investor_groups`, `investor_group_members`, `investment_projects`, `investments`, `project_cost_items`, `project_settlements`, `settlement_payouts`, `channel_partners`, `channel_partner_payouts`, `payout_batches`, `payout_batch_items`, `investor_cycle_elections`, `investment_withdrawal_notices`, `investor_requests`, `agreement_templates`, `agreement_acceptances`, `investment_documents`, `investment_witnesses`, `investor_security_instruments`, `audit_logs`, `notifications`।

### ৩.২ বদল (কলাম যোগ)

| টেবিল | নতুন কলাম | কেন |
|---|---|---|
| `companies` | `ims_enabled` (bool), `ims_domain` (nullable, ইউনিক) | আলাদা মূল ডোমেন (FR-90–92) |
| `investors` | `kyc_status` (`not_submitted`/`pending`/`verified`/`rejected`), `kyc_submitted_at`, `kyc_reviewed_at`, `kyc_reviewed_by`, `kyc_note`, `kyc_check` (json: চেকলিস্টের ফল), `email_verified_at` | KYC (FR-10–14) |
| `investments` | `status` এ `pending` যোগ; `tenor_months` (nullable), `maturity_date` (nullable), `maturity_reminder_sent_at`, `confirmed_at`, `confirmed_by` | PENDING, মেয়াদ (FR-22–24, 53) |
| `investor_group_members` | `tenor_months`, `maturity_date`, `maturity_reminder_sent_at` | গ্রুপ-সদস্যেরও ঐচ্ছিক মেয়াদ |
| `settlement_payouts` | `capital_loss_amount` (≥ 0) | ক্ষতির কাটা অংশ (FR-42) |
| `project_settlements` | `investor_loss_percent` স্ন্যাপশট, `locked_at` | ক্ষতির % স্ন্যাপশট; হিসাব ফ্রিজ (FR-47) |

### ৩.৩ নতুন টেবিল

| টেবিল | মূল কলাম | কাজ |
|---|---|---|
| `investor_nominees` | `investor_id`, `name`, `relation`, `phone`, `nid` (enc), `share_percent`, `deleted_at` | ১–৫ নমিনি (FR-06)। এখনকার `investors.nominee_*` থেকে কপি করে আনা (মোছা নয়) |
| `investor_kyc_documents` | `investor_id`, `type` (`nid_front`/`nid_back`/`selfie`/`other`), `path` (প্রাইভেট), `ocr_result` (enc json), `uploaded_by_type/id` | KYC ছবি (FR-10–11) |
| `investor_invites` | `contact` (ফোন/ইমেইল), `token_hash`, `expires_at` (১৪ দিন), `invited_by`, `used_at`, `revoked_at` | ইনভাইট (FR-04) |
| `investor_otp_codes` | `contact`, `code_hash`, `purpose`, `attempts`, `expires_at` (১০ মিনিট), `last_sent_at` | OTP (FR-04) |
| `investment_project_purchases` | `project_id`, `purchase_id` | প্রজেক্ট ↔ ERP কেনাকাটা (FR-44 ক)। এখনকার একক `purchase_id` রাখা হবে, এটা একাধিকের জন্য |
| `capital_returns` | `investment_id` বা `group_member_id`, `amount`, `returned_on`, `method`, `reference`, `proof_path`, `status` (`paid`/`voided`), `void_reason`, `voucher_id` | মেয়াদে মূলধন ফেরত (FR-53, 55) |
| `profit_proposals` | `project_id`, `period_start/end`, `figures` (json), `status` (`pending`/`approved`/`dismissed`/`stale`), `decided_by`, `reason`, `settlement_id` | অটো প্রস্তাব (FR-45) |
| `project_updates` | `project_id`, `title`, `body`, `video_url`, `visible_to_investors`, `published_at` | আপডেট (FR-30) |
| `project_update_photos` | `update_id`, `path`, `sort` | ≤ ১২ ছবি |
| `project_milestones` | `project_id`, `title`, `due_on`, `done_on`, `sort` | মাইলস্টোন (FR-31) |
| `project_reports` | `project_id`, `period_start/end`, `status` (`draft`/`published`), `summary` (ফ্রিজ json), `published_at` | রিপোর্ট (FR-36) |
| `feed_posts`, `feed_post_images`, `feed_post_views`, `feed_post_likes`, `feed_comments` | ডক §10-এর মতো | ফিড (FR-63) |
| `support_threads`, `support_messages` | `investor_id`, `project_id?`, `status`, `staff_unread`, `investor_unread` / `thread_id`, `body`, `from_staff` | সাপোর্ট (FR-64)। পুরনো `investor_requests`-এর `support` টাইপ থ্রেডে আনা |
| `statement_dispatches` | `investor_id`, `period` (ইউনিক জোড়া), `sent_at` | মাসিক স্টেটমেন্ট দুবার নয় (FR-65) |
| `bank_statement_imports`, `bank_statement_lines` | `source`, `file`, `line_hash` (ইউনিক), `amount`, `reference`, `counterparty`, `status`, `matched_type/id` | স্টেটমেন্ট মেলানো (FR-24) |
| `ims_settings` (বা কোম্পানি সেটিং) | নোটিফিকেশন চ্যানেল, রেজিস্ট্রেশন সুইচ, রিমাইন্ডার দিন | IMS সেটিংস |

প্রতিটা নতুন টেবিলে `company_id`। টাকা `decimal(15,2)`। আর্থিক রেকর্ড কখনো hard-delete নয় (void/soft-delete)।

## ৪. টাকার নিয়ম (সূত্র)

### ৪.১ লাভ (FR-40, 41)

```
netProfit       = totalRevenue − totalCost              (বা স্টাফের হাতে লেখা লাভ)
investorPool    = round2(netProfit × investorPercent ÷ 100)
partnerPool     = round2(netProfit × partnerPercent ÷ 100)
partner_p       = round2(partnerPool × referredCapital_p ÷ externalCapital)
capitalBase     = externalCapital + companyContribution
share_i         = round2(investorPool × capital_i ÷ capitalBase)
                  (কোম্পানির অবদান না থাকলে শেষ জন বাকি পয়সা পায়, যাতে যোগফল = investorPool)
companyNet      = netProfit − Σ share_i − Σ partner_p
```
এটা এখনকার `SettlementService::allocateProfit()`; বদলাবে না।

### ৪.২ ক্ষতি (FR-42) — বদলাবে

```
loss            = −netProfit                             (> 0)
investorLoss    = round2(loss × investorPercent ÷ 100)   ← নতুন: পুরো ক্ষতি নয়, % অনুযায়ী
lossShare_i     = min(capital_i, round2(investorLoss × capital_i ÷ capitalBase))
companyLoss     = loss − Σ lossShare_i
```
- মালিকের উদাহরণ: % = ৩৫, ক্ষতি ১,০০,০০০ → ইনভেস্টর −৩৫,০০০, কোম্পানি −৬৫,০০০।
- `lossShare_i` → `settlement_payouts.capital_loss_amount`; payout ০।
- গ্রুপে: সদস্যের পরের স্ন্যাপশটের টাকা = আগের টাকা − মোট ক্ষতি (কখনো ০-এর নিচে নয়)। এখনকার ব্লক (`SettlementService` লাইন ~১২০) সরবে।
- "কোম্পানি বহন" (`loss_manager_borne`) আগের মতো: ইনভেস্টরের ক্ষতি ০।
- পরের লাভ ক্ষতি পূরণ করে না (ডক §3)।

### ৪.৩ মূলধন (FR-55)

```
capitalLoss         = min(amount, Σ capital_loss_amount  [confirmed সেটেলমেন্ট])
returnableCapital   = amount − capitalLoss
outstandingCapital  = max(0, returnableCapital − Σ capital_returns.amount [paid])
```
এক জায়গায় (`App\Ims\Capital\CapitalCalculator`) — হোম, পোর্টাল, স্টেটমেন্ট, করণীয় সবাই এটাই ডাকবে। কোথাও `amount − returned` আলাদা করে লেখা চলবে না।

### ৪.৪ অটো লাভের হিসাব (FR-44 ক)

- প্রজেক্টের লিংক করা কেনাকাটার (`investment_project_purchases`) পণ্যের বিক্রি: ERP `Order`/`OrderItem` (অবস্থা `confirmed`/`processing`/`completed`, ERP-র নিজের নিয়ম), সাইকেলের তারিখের মধ্যে, রিটার্ন বাদ।
- খরচ: `project_cost_items` + লিংক করা কেনাকাটার ল্যান্ডেড কস্ট।
- একই পণ্য একাধিক প্রজেক্টে থাকলে: সেই বিক্রি অটো হিসাবে ধরা হয় না, অফিস লাভ হাতে লেখে (মালিক, 2026-10-19)।
- সিস্টেমের সংখ্যা শুধু প্রস্তাব; স্টাফ মেনে নেয় বা হাতে লেখে (`basis` = `sales` / `manual`, আগে থেকেই আছে)।

### ৪.৫ সাধারণ টাকার নিয়ম

- টাকা সবসময় দশমিক স্ট্রিং/`decimal`, ২ ঘর, `ROUND_HALF_UP`; PHP float-এ যোগ নয় (নতুন কোডে `brick/math` বা BCMath — বানানোর সময় ঠিক)।
- এক পরিমাণের সীমা ১০০০ কোটি (টাইপো ধরতে)।
- প্রতিটা টাকার কাজ ট্রানজ্যাকশনে + `lockForUpdate` (দুবার চলা আটকাতে)।

## ৫. সার্ভিস (`app/Ims/`)

| সার্ভিস | কাজ | এখনকার কোড |
|---|---|---|
| `Onboarding\InviteService`, `OtpService` | ইনভাইট, OTP | নতুন |
| `Kyc\KycService`, `Kyc\NidOcr` | জমা, OCR, চেকলিস্ট, রিভিউ | নতুন |
| `Profit\SettlementCalculator` | §৪.১–৪.২ | `SettlementService` থেকে সরানো/বাড়ানো |
| `Profit\ProposalService` | অটো প্রস্তাব | নতুন |
| `Profit\ProjectFinancials` | §৪.৪ | নতুন |
| `Capital\CapitalCalculator`, `CapitalReturnService` | §৪.৩, ফেরত, void | নতুন |
| `Transparency\InvestorProjectSummary` | গোপন তথ্য বাদ দিয়ে মোট সংখ্যা | নতুন |
| `Reports\ProjectReportService`, `StatementService` | PDF | বাড়ানো |
| `Feed\FeedService`, `Support\SupportService` | ফিড, সাপোর্ট | নতুন |
| `Notifications\ImsNotifier` | ইন-অ্যাপ + SMS + ইমেইল এক জায়গা থেকে | নতুন (SMS অংশ আছে) |
| `Reconciliation\StatementImporter`, `Matcher` | স্টেটমেন্ট মেলানো | নতুন |
| `Todo\OwnerTodo` | করণীয় তালিকা | নতুন |

এখনকার সার্ভিস (`InvestorGroupService`, `PayoutBatchService`, `PayoutCompletionService`, `CycleElectionService`, `InvestmentWithdrawalService`, `InvestmentLedgerService`, `InvestorPortalAccountService` …) যেখানে আছে সেখানে থাকবে; IMS সার্ভিস এগুলো ডাকবে।

## ৬. শিডিউল জব

প্রতিটা প্রতি কোম্পানি লুপ, `ModuleGuard`-এ মোড়া, সেটিংসে বন্ধ করা যায়, একই দিনে দুবার কিছু পাঠায় না।

| জব | কখন | কাজ |
|---|---|---|
| `investments:advance-groups` | দৈনিক | আছে — গ্রুপ সাইকেল |
| `ims:profit-proposals` | দৈনিক ০৭:০০ | সাইকেল শেষ হলে প্রস্তাব |
| `ims:maturity-reminders` | দৈনিক ০৮:০০ | মেয়াদের ৭ দিন আগে, একবার |
| `ims:owner-digest` | দৈনিক ০৮:০৫ | করণীয় তালিকা (খালি হলে কিছু নয়) |
| `ims:monthly-statements` | মাসের ১ তারিখ | PDF স্টেটমেন্ট (দুবার নয়) |
| `ims:shipment-delays` | প্রতি ঘণ্টা | প্রত্যাশিত তারিখ পেরোলে অ্যালার্ট (একবার) |

## ৭. KYC-র OCR

- ডক: Tesseract (বাংলা + ইংরেজি), নিজের সার্ভারে, ছবি বাইরে যায় না।
- ERP-তে: কন্টেইনারে `tesseract-ocr` + `tesseract-ocr-ben` প্যাকেজ লাগবে (Nixpacks/Coolify বিল্ডে যোগ), PHP থেকে প্রসেস হিসেবে ডাকা। **VPS এজেন্টকে দিয়ে যাচাই করাতে হবে** যে বিল্ডে প্যাকেজ বসানো যায়।
- পার্সিং ডক §35-এর মতো: NID ১০/১৩/১৭ অঙ্ক, OCR ভুল ঠিক (`O→0`, `I/l→1`, `S→5`, `B→8`), বাংলা অঙ্ক, জন্মতারিখ, নাম।
- ইনভেস্টর নিজে জমা দিলে কার্ড পড়া যেতে হবে আর NID ও জন্মতারিখ মিলতে হবে; স্টাফ জমা দিলে বাধা নেই।
- OCR ফল এনক্রিপ্টেড; অডিটে শুধু পাস/ফেল, নাম/নম্বর নয়। সীমা: ইনভেস্টর ২০/ঘণ্টা, স্টাফ ১০০/ঘণ্টা।
- OCR না চললে: স্টাফ হাতে চেক করে অনুমোদন (ইনভেস্টরের জমা আটকাবে না, চেকলিস্টে "OCR পড়া যায়নি")।

## ৮. নোটিফিকেশন

- `ImsNotifier::send(event, recipient, data)`: ইন-অ্যাপ (Laravel `notifications` টেবিল, পোর্টাল অ্যাকাউন্ট notifiable) + SMS + ইমেইল (কিউতে)।
- ব্যবসার ট্রানজ্যাকশন commit হওয়ার পরে পাঠানো (`afterCommit`), তাই ইমেইল ব্যর্থ হলে টাকার কাজ ফেরত যায় না।
- ভাষা = ইনভেস্টরের বেছে নেওয়া ভাষা।
- কোন ঘটনা কোন পথে — IMS সেটিংস। SMS-এর লেখা মালিক নিজে লেখেন (এখনকার `SmsTemplates`)।
- ইমেইলের জন্য কোম্পানির ইমেইল সেটিং (`08_EMAIL_CONFIGURATION_PLAN.md`) লাগবে।

## ৯. নিরাপত্তা

বিস্তারিত তালিকা `ROLES.md` §৪। প্রযুক্তিগত দিক:

- **প্যানেল আলাদা:** অফিস = স্টাফ guard (`web`), পোর্টাল = `investor` guard; আলাদা সেশন কুকি; IMS ডোমেনে `SESSION_DOMAIN` ফাঁকা।
- **লগইন:** `LoginGate` দুটো প্যানেলে; পোর্টাল লগইন ফোন/ইমেইল + পাসওয়ার্ড।
- **পাসওয়ার্ড:** বদলাতে বর্তমান পাসওয়ার্ড; বদলালে অন্য সব সেশন ও বিশ্বস্ত ডিভাইস বাতিল; রিসেট টোকেন/কোড hash করে রাখা, একবারই, সময়সীমা।
- **এনক্রিপশন:** NID, নমিনির NID, ব্যাংক অ্যাকাউন্ট, OCR ফল — Laravel encrypted cast; খোঁজার জন্য `nid_hash` (আছে)।
- **ফাইল:** প্রাইভেট ডিস্ক; ধরন যাচাই ফাইলের বাইট দেখে (JPEG/PNG/WEBP/PDF); অনুমতি দেখে ডাউনলোড; `Cache-Control: private, no-store`।
- **ইনভেস্টরের কোয়েরি:** সবসময় লগইন করা অ্যাকাউন্টের `investor_id` দিয়ে; ব্রাউজার থেকে আসা `investor_id` বিশ্বাস নয়। পোর্টালে আলাদা "select-তালিকা" (শুধু অনুমোদিত কলাম)।
- **রেট-লিমিট:** OTP (ঘণ্টায় ৫), কমেন্ট (৫ মিনিটে ১০), লাইক (৫ মিনিটে ৬০), সাপোর্ট (১০ মিনিটে ১০), OCR (§৭)।
- **অডিট:** প্রতিটা টাকার কাজ, KYC সিদ্ধান্ত, void, লগইন, ডোমেন/সেটিং বদল — `AuditLogService`; পাসওয়ার্ড/টোকেন/NID লগে নয়।
- **CSRF:** Filament/Laravel ডিফল্ট; আলাদা ডোমেনেও চলে।

## ১০. টেস্ট

| টেস্ট | কী ধরে |
|---|---|
| `Ims\SettlementLossTest` | ক্ষতি × %, মূলধনের সীমা, গ্রুপে কম মূলধন পরের স্ন্যাপশটে, "কোম্পানি বহন" |
| `Ims\SettlementProfitTest` | এখনকার লাভের হিসাব না বদলানো (রিগ্রেশন) |
| `Ims\CapitalCalculatorTest` | ডক §32-এর মূলধন-ক্ষতির ৭টা পরিস্থিতি (ইনভেস্টর কখনো ঋণী নয়, void-এ ফেরত, বাকির বেশি ফেরত নিষেধ) |
| `Ims\KycTest` | চেকলিস্ট, KYC ছাড়া ACTIVE নিষেধ, পুরনো ইনভেস্টরের ছাড় |
| `Ims\OnboardingTest` | ইনভাইট মেয়াদ, OTP সীমা, ইনভাইট না থাকলেও একই উত্তর |
| `Ims\PortalPrivacyTest` | প্রতিটা পোর্টাল রুট/Livewire উত্তরে কাস্টমার, সাপ্লায়ার, দাম, অন্য ইনভেস্টরের তথ্য নেই |
| `Ims\VisibilityTest` | দৃশ্যমানতার ৩ স্তর |
| `Ims\DomainRoutingTest` | IMS ডোমেনে শুধু IMS, ERP ডোমেনে IMS নয়, `www.` চলে |
| `Ims\JobsTest` | প্রতিটা জব একবারই, বন্ধ থাকলে চলে না |
| `Ims\ProposalTest` | প্রস্তাব নিজে কিছু ভাগ করে না; পুরনো প্রস্তাব stale |
| `Ims\FreezeTest` | ফ্রিজ হওয়া সাইকেলে খরচ/বিক্রি বদল নিষেধ |
| `MultiCompanyIsolationTest` | প্রতিটা নতুন মডেল যোগ |
| `TranslationParityTest` | প্রতিটা নতুন লেখা দুই ভাষায় |

## ১১. ডেটা স্থানান্তর (ফেজ ৭)

- পুরনো IMS-এর ডেটা: আগে `--dry-run` (ম্যাপিং ও যোগফল দেখায়, না মিললে বন্ধ), তারপর এক ট্রানজ্যাকশনে।
- এখনকার `investors.nominee_*` → `investor_nominees` (শেয়ার ১০০%), পুরনো কলাম মোছা হবে না।
- এখনকার `investor_requests` (`support`) → `support_threads`।
- কিছুই মোছা নয়, কিছুই আবার হিসাব নয়।

## ১২. ডিপ্লয়

- একই Coolify অ্যাপ; Domains-এ IMS ডোমেন (`@` ও `www`) যোগ; Cloudflare A রেকর্ড; SSL Full (strict)। VPS এজেন্ট যাচাই করেছে (`PLAN.md`)।
- প্রতিটা ফেজে `php artisan migrate --force`।
- OCR প্যাকেজের জন্য বিল্ড বদল (ফেজ ২)।
- কিউ ওয়ার্কার চালু থাকতে হবে (ইমেইল, SMS, PDF)।
