# 18 — Every company in one IMS on zamzaminv.app

> **বাংলা সারসংক্ষেপ:** অনুরোধ (২০২৬-১০-২০): ERP-র **সব কোম্পানি** IMS-এ আসবে, আর IMS-এর সব কাজ হবে শুধু **zamzaminv.app**-এ। কোনো আলাদা সাবডোমেন থাকবে না। অফিসে (`zamzaminv.app/office`) ERP-র Super Admin আর অফিস অ্যাডমিনের সব কোম্পানির সব কিছুতে পুরো অ্যাক্সেস থাকবে, আর তাঁরা বাকিদের রোল অনুযায়ী অ্যাক্সেস দিতে পারবেন। অফিসে কোম্পানি বাছার অপশন থাকবে, আর একটা "সব কোম্পানি" পাতা থাকবে। UI হবে সবচেয়ে সহজ (নন-টেকনিক্যাল মানুষের জন্য)। ইনভেস্টর পোর্টালে কোম্পানি বাছতে হবে না: সব প্রজেক্ট এক তালিকায় আসবে, প্রতিটা প্রজেক্টের সাথে কোম্পানির নাম স্বাভাবিকভাবে দেখাবে, আর প্রজেক্ট ফিল্টার থাকবে। নাম আর লোগো পরে সেটিংস থেকে বসানো হবে।

Status: **plan, answers from 2026-10-20** (see §6). Follows `17_IMS_IN_PORTAL_PLAN.md` and `IMS-V2/` (ROLES, DESIGN). **Phases A, B, C and D built 2026-10-20** on `feature/ims-all-companies` ("office admin" = the permission `ims.all_companies`; the All companies home is `/office/all-companies`, reached from the company button, and its short menu is the row of jump buttons at the top); E not built yet. Phase D: a project of another company opens after switching the session to the person's own account in that company (`?business=<id>`, the same checked switch as the business switcher), so its photos, reports and forms work under that company.

The phase A gap ("Forgot password?" and the SMS-code sign-in looked only at the domain's own company) is closed in phase D. Still per company until phase E: the home's chart, cards and calendar, Statement, Payouts, Documents and Requests (the Switch business button stays until then).

## 1. Today

- `zamzaminv.app` is the IMS domain of **one** company. `/office` and `/investor` open under that company only (`ResolveCompanyFromImsDomain`, `ResolveCompanyFromInvestorPortalDomain`, `CompanyContext`).
- Every IMS feature asks the company `imsIsLive()` (own `ims_enabled` + `ims_domain`): KYC before new money, IMS invites, monthly statements, payout/settlement messages, the ERP's `/admin/investments` redirect to the office, the daily IMS jobs (`ImsCompanyCommand`).
- Investors: one sign-in on `zamzaminv.app` opens all their businesses (2026-10-20), but they switch business by business, and only businesses whose own portal is on.
- Every page renders under one company's context, so no query mixes companies. This plan keeps that rule: "all companies" figures are added up company by company.

## 2. Every company runs on the one IMS domain

- The IMS domain stays on the company that has it (`ims_enabled` + `ims_domain`, today ZamZam International → `zamzaminv.app`). **Every other active company uses that same domain** — no switch per company, no domain of its own.
- `Company::imsHost()` = the company's own IMS domain, else the group IMS domain (the IMS-live company; if more than one ever exists, the lowest id). `imsIsLive()`, `imsUrl()`, `investorPortalHost()` / `investorPortalUrl()` use it, so every company's invites, statements, links and messages point at `zamzaminv.app`.
- Effect to know: the IMS rules then apply to every company (for example KYC before new money, the ERP's Investments menu opening in the IMS office). That is what "every company in the IMS" means.
- Pages that belong to one company and arrive by link (invite, join, partner sign-up, staff link, password reset) carry which company they are for, and the page checks the token against that company — so a link from any company works on `zamzaminv.app`.
- **The ERP and the IMS (final, 2026-10-20):** one application, one database, so nothing is copied or synced — a change made in one shows in the other at once. All investment work is done in the IMS office. The ERP keeps only (a) **Investments → Investor Portal Settings** (switching IMS and its domain on/off) and (b) the **Investments** menu as a button that opens the IMS office; any old `/admin/investments/...` address goes to the same page in the office (`RedirectInvestmentsToImsOffice`, today for the IMS company, after phase A for every company). ERP work that only touches a project stays where it is (a purchase linked to a project is still made on the ERP's purchase screen, accounting entries are still automatic), and the IMS reads it from the same tables.
- `ims.zamzaminv.app` keeps working until its setting is cleared; then it is redirected to `zamzaminv.app` at Coolify/DNS level. Old per-company portal addresses redirect to `zamzaminv.app` (already the rule for a company on IMS).

## 3. Office (`zamzaminv.app/office`)

### 3.1 Who can do what
- Signed in with the ERP staff account (as today). **ERP Super Admins and office admins have full access to every company**; they give everyone else access by role (Settings → Roles, existing permissions) and by company (the companies a user works for).
- Other staff see only their own companies, with their role's permissions. Checked on every request, never from the session alone.

### 3.2 Company picker
- At the top: **All companies** + each company the person may open. Choosing one keeps it in the session; every office page then works on that company exactly as today. The name and logo at the top stay the IMS domain's (set later in settings).

### 3.3 "All companies" home (simplest UI)
Big cards, plain Bangla, few words, large buttons (IMS-V2/DESIGN.md, mobile-first):
1. **Total money** invested and **capital at work**, all companies.
2. **Waiting for a decision** — items that already wait in today's workflow, from every company (profit proposals, settlements, payout batches, capital returns, KYC to review, money to confirm), each with the company name and the same action buttons staff already use, with the same permissions. No new approval rule.
3. **Profit to pay** and the next payouts.
4. **Companies** — one card per company (money in, projects running, profit this cycle); tap to open it.
5. **Projects** — every running project from every company in one list; tap to open.
6. **New investors** this month.
- Short menu for this view: Home, Waiting, Companies, Projects, Investors, Reports, and **More** for every other page the person's role allows.
- Totals are per currency, never added across currencies.

## 4. Investor portal: projects, not companies

- After sign-in: **My projects** — every project the person put money in, from every company, in one list; each card shows the project and its **company name** as normal text.
- **Home** totals add up all their projects (per currency).
- **Project page** opens a project of any company after checking the person has an active account in that company.
- **Statement, Payouts, Documents, Requests**: one list across all projects, newest first, each line with project and company, and a **Project filter** (one select, empty = all).
- Requests (add money, withdraw, support) are made for a project, so they land in that project's company.
- One person = the same phone with an account in each company (as now); every figure is read under its own company. The Switch business button and My businesses page go away once this is live.

## 5. Phases

| Phase | What |
|---|---|
| **A — one domain for all** | `imsHost()` and the IMS-live rule for every company; links and token pages carry their company; daily IMS jobs run for every company. |
| **B — office company picker** | Picker + access rules (§3.1–3.2). |
| **C — "All companies" home** | §3.3. |
| **D — investor projects** | My projects, combined home, project page from any company (§4). |
| **E — combined lists** | Statement, payouts, documents, requests across companies with the Project filter. |

Each phase: company-isolation tests first, Bangla + English strings, CHANGELOG, browser check on phone and desktop, on its own feature branch; `main` only on approval.

## 6. Answers (2026-10-20)

1. All ERP companies come into the IMS.
2. No separate "owner" role: office admins and ERP Super Admins have full access and can grant role-based access to others.
3. Name and logo on `zamzaminv.app`: set later in settings (nothing hardcoded).
4. The project shows its company name normally.
5. Investor lists have a Project filter.
6. Main domain `zamzaminv.app`; every IMS activity there, no subdomain.
