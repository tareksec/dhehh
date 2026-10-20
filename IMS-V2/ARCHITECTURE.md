# IMS v2 — Architecture

> সিস্টেম কীভাবে সাজানো: ডোমেন, প্যানেল, ফোল্ডার, ডেটা কোথা থেকে আসে, কোন অংশ কাকে ডাকে।
> ডেটা মডেল ও নিয়ম: `TRD.md`। Status: **প্ল্যান।**

---

## ১. এক লাইনে

**একটাই Laravel অ্যাপ, একটাই ডেটাবেস।** IMS v2 এই ERP-র ভেতরে নিজের ফোল্ডারে থাকবে, আর আলাদা মূল ডোমেনে খুলবে (মালিকের সিদ্ধান্ত "অপশন A", 2026-10-19)।

## ২. অনুরোধ কোন পথে যায়

```
ইনভেস্টর/স্টাফের ব্রাউজার
        │  https://<IMS ডোমেন>            https://app.zamzamint.com
        ▼                                          ▼
Cloudflare (DNS: @ আর www → VPS IP, প্রক্সি চালু, SSL Full strict)
        ▼
VPS → Coolify → Traefik (Domains তালিকা দেখে, Let's Encrypt SSL)
        ▼
একটাই Laravel কন্টেইনার
        ▼
RestrictPortalHosts (কোন ডোমেনে কোন প্যানেল)
   ├─ IMS ডোমেন  → "/"        → IMS পোর্টাল (ইনভেস্টর ও চ্যানেল পার্টনার)
   │              → "/office"  → IMS অফিস (স্টাফ)
   │              → অন্য কিছু  → "/"-এ ফেরত
   ├─ app.zamzamint.com → "/admin" ERP (IMS-এর পথ এখানে 404)
   ├─ reseller.… → রিসেলার পোর্টাল (আগের মতো)
   └─ offer.… / দোকানের ডোমেন → স্টোরফ্রন্ট (আগের মতো)
```

VPS যাচাই (মালিকের VPS এজেন্ট, 2026-10-19): Traefik, পোর্ট ৮০/৪৪৩ খোলা, একই অ্যাপে এখনই ৫টা ডোমেন চলছে, নতুন ডোমেনে SSL নিজে হবে। বিস্তারিত `PLAN.md`।

## ৩. ডোমেন থেকে কোম্পানি চেনা

- `companies.ims_domain` (অ্যাডমিন সেটিংস, শুধু সুপার অ্যাডমিন বসায়)।
- `Company::forImsHost($host)`: হোস্ট ছোট হাতের করে, `https://`, পোর্ট আর `www.` বাদ দিয়ে (`Company::normalizeDomain()`, আগে থেকেই আছে) `ims_domain`-এর সঙ্গে হুবহু মেলায়। **কোনো সাবডোমেন-নিয়ম নেই।**
- মিললে `CompanyContext` ওই কোম্পানিতে সেট → `CompanyScope` প্রতিটা কোয়েরি ওই কোম্পানিতে আটকে রাখে।
- না মিললে IMS-এর পথ 404।
- এখনকার ইনভেস্টর পোর্টালের ডোমেন (`investor_portal_domain`, যেমন `inv.tasneemknitindustry.com`) IMS চালু হওয়ার পর IMS ডোমেনে রিডাইরেক্ট হবে; এখনকার ব্যবস্থা ধাপে ধাপে বদলাবে, একদিনে নয়।

`RestrictPortalHosts` এখন "এক ডোমেন → এক পথ" বোঝে। IMS-এর জন্য "এক ডোমেন → দুই পথ (`/` আর `/office`)" যোগ হবে।

## ৪. প্যানেল

| প্যানেল | পথ | লগইন | কারা | Provider |
|---|---|---|---|---|
| IMS পোর্টাল | `/` | `investor` guard, ফোন/ইমেইল + পাসওয়ার্ড | ইনভেস্টর, চ্যানেল পার্টনার | `ImsPortalPanelProvider` (এখনকার `InvestorPanelProvider` থেকে) |
| IMS অফিস | `/office` | স্টাফ guard, ERP-র ইউজার | মালিক, অ্যাডমিন, অ্যাকাউন্ট্যান্ট, স্টাফ | `ImsOfficePanelProvider` (নতুন) |
| ERP অ্যাডমিন | `/admin` | স্টাফ guard | স্টাফ | `AdminPanelProvider` (আগের মতো) |

- একই স্টাফ ইউজার ERP আর IMS অফিস দুটোতেই লগইন করতে পারে (একই অ্যাকাউন্ট), কিন্তু ডোমেন আলাদা বলে সেশন আলাদা — প্রতিটায় আলাদা লগইন।
- অফিসে কী দেখা যাবে তা `ROLES.md`-এর অনুমতি ঠিক করে।
- ERP অ্যাডমিনের ইনভেস্টর মেনু অফিসে সরবে; পুরনো লিংক অফিসে রিডাইরেক্ট।

## ৫. ফোল্ডার

```
IMS-V2/                          ← প্ল্যান ও ডকুমেন্ট (এই ফোল্ডার)
app/Ims/                         ← IMS-এর ব্যবসার লজিক
    Onboarding/  Kyc/  Profit/  Capital/  Transparency/
    Reports/  Feed/  Support/  Notifications/  Reconciliation/  Todo/
app/Filament/Ims/Office/         ← অফিসের পেজ, রিসোর্স, উইজেট
app/Filament/Ims/Portal/         ← পোর্টালের পেজ (এখনকার app/Filament/Investor থেকে)
app/Providers/Filament/ImsOfficePanelProvider.php
app/Providers/Filament/ImsPortalPanelProvider.php
app/Http/Middleware/ResolveCompanyFromImsDomain.php
app/Console/Commands/Ims/        ← শিডিউল জব
resources/css/filament/ims/      ← IMS-এর থিম (DESIGN.md-এর টোকেন)
resources/views/ims/             ← ভিউ, PDF
app/Models/                      ← মডেল (রিপোর নিয়মে এখানেই)
database/migrations/             ← মাইগ্রেশন
lang/en.json, lang/bn.json       ← সব লেখা
tests/Feature/Ims/               ← টেস্ট
```

## ৬. ERP-র সঙ্গে সংযোগ

IMS v2 ERP-র ডেটা সরাসরি পড়ে — কোনো API বা sync নেই।

```
ERP (যা আছে)                               IMS v2 (যা পড়ে/লেখে)
─────────────────────────────────────────  ────────────────────────────────────────
Purchase, PurchaseItem      ── পড়ে ──▶   প্রজেক্টের খরচ ও লিংক করা কেনা
Order, OrderItem, OrderPayment ─ পড়ে ─▶   প্রজেক্টের বিক্রি (অটো হিসাব, মোট সংখ্যা)
StockMovement               ── পড়ে ──▶   প্রজেক্টের স্টক (মোট সংখ্যা)
Shipment, Container         ── পড়ে ──▶   শিপমেন্ট ভিউ (শুধু অনুমোদিত কলাম)
Expense                     ── পড়ে ──▶   খরচ
Voucher, FundSource         ◀── লেখে ──   বিনিয়োগ গ্রহণ, payout, মূলধন ফেরত (এখনকার InvestmentLedgerService)
SMS সেটিং, SmsTemplates     ◀── ব্যবহার ── নোটিফিকেশন
User, roles, permissions    ◀── ব্যবহার ── অফিসের লগইন ও অনুমতি
Company, CompanyContext     ◀── ব্যবহার ── কোম্পানি আলাদা রাখা
```

পোর্টালে ERP-র ডেটা যায় শুধু `Transparency\InvestorProjectSummary`-র ভেতর দিয়ে, যেটা গোপন কলাম কখনো বের করে না (`PortalPrivacyTest`)।

## ৭. ভেতরের স্তর

```
Filament পেজ (Office / Portal)          ← শুধু দেখানো আর ইনপুট
        │ ডাকে
app/Ims/* সার্ভিস                        ← নিয়ম, হিসাব, ট্রানজ্যাকশন, অডিট, নোটিফিকেশন
        │ ব্যবহার করে
এখনকার Investment সার্ভিস + মডেল         ← গ্রুপ, সেটেলমেন্ট, payout, লেজার
        │
MySQL (company_id দিয়ে আলাদা)
```

নিয়ম: পেজ কখনো সরাসরি টাকা হিসাব করে না; সব হিসাব সার্ভিসে, যাতে পোর্টাল, অফিস, PDF আর ইমেইল একই সংখ্যা দেখায়।

## ৮. মূল প্রবাহ (ধাপে ধাপে)

### ৮.১ সাইকেল শেষে লাভ বণ্টন

1. `investments:advance-groups` সাইকেল শেষ করে প্রজেক্ট Closed করে, পরের প্রজেক্ট শুরু করে।
2. `ims:profit-proposals` → `Profit\ProjectFinancials` হিসাব করে → `profit_proposals` (pending) → অফিসে করণীয় + অ্যাডমিনকে খবর।
3. অ্যাডমিন অনুমোদন (চাইলে লাভ হাতে লেখে) → `SettlementCalculator` → `project_settlements` (draft) + `settlement_payouts`।
4. Confirm → ইনভেস্টরদের খবর (`ImsNotifier`)।
5. ইনভেস্টর পোর্টালে নেবে/রিইনভেস্ট বেছে নেয় (`CycleElectionService`)।
6. অ্যাকাউন্ট্যান্ট পরিশোধ করে (একটা একটা বা ব্যাচে) → ভাউচার → স্ক্রিনশট → ইনভেস্টরকে খবর।
7. সব পরিশোধ হলে `locked_at` → ওই সাইকেলের খরচ/বিক্রি ফ্রিজ।

### ৮.২ নতুন ইনভেস্টর

1. অফিস ইনভাইট পাঠায় (`investor_invites`) → SMS/ইমেইলে লিংক।
2. ইনভেস্টর IMS ডোমেনে লিংক খোলে → OTP → পাসওয়ার্ড, প্রোফাইল, নমিনি।
3. KYC জমা → OCR → অফিসে রিভিউ → verified।
4. খোলা গ্রুপ বেছে টাকা পাঠায় → বিনিয়োগ pending।
5. অ্যাকাউন্ট্যান্ট নিশ্চিত করে বা স্টেটমেন্ট মিলে যায় → KYC থাকলে active → রিসিভ ভাউচার → খবর।

## ৯. ত্রুটি হলে

- প্রতিটা উইজেট/ব্যাজ/সব-পেজের অংশ `ModuleGuard`-এ: একটা অংশ ভাঙলে বাকি পেজ চলে, ভুলটা সুপার অ্যাডমিনের Deploy Error Logs-এ যায়।
- জব ব্যর্থ হলে পরের দিন আবার চলে, দুবার কিছু পাঠায় না।
- OCR না চললে KYC হাতে রিভিউ হয় (`TRD.md` §৭)।
