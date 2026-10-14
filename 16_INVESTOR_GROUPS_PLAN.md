# 16 — Investor Groups Plan (group-based projects)

> **বাংলা সারসংক্ষেপ:** আজ সিস্টেম **প্রজেক্ট-ভিত্তিক বিনিয়োগ**: ইনভেস্টর একটা প্রজেক্টে টাকা দেন। মালিকের নতুন নিয়ম **গ্রুপ-ভিত্তিক প্রজেক্ট**: ইনভেস্টর একটা **গ্রুপে** টাকা রাখেন। গ্রুপ "ওপেন" থাকলে সদস্য যোগ হয়। গ্রুপ "রানিং" হলে সদস্য-তালিকা বন্ধ হয়, আর ওই তারিখ থেকে প্রতি ২ মাস অন্তর একটা প্রজেক্ট (ফেজ) নিজে থেকে শেষ হয় এবং পরের প্রজেক্ট চালু হয়। প্রতিটা প্রজেক্টের সদস্য ও অঙ্ক চালুর মুহূর্তে সংরক্ষিত হয়, তাই পরে গ্রুপে যা-ই বদলাক, চলমান ও পুরনো সেটেলমেন্ট বদলায় না। লাভ-লোকসানের গণিত (`SettlementService`) আগের মতোই থাকে। নতুন টাকা (টপ-আপ বা রিইনভেস্ট) কখনো চলমান গ্রুপে ঢোকে না, ঢোকে একটা নতুন ওপেন গ্রুপে। ইনভেস্টর পোর্টালে গ্রুপের কার্ড, প্রগ্রেস বার, "প্রজেক্ট সম্পন্ন" অবস্থা, আর সেটেলমেন্টের পর পেমেন্ট-স্ক্রিনশটসহ Payouts পেজে সংরক্ষণ। §9-এ মালিকের উত্তর আর যে ডিফল্টে এগোব তার তালিকা আছে। মালিকের অনুমোদন ছাড়া কোড শুরু হবে না।

Status: **approved by the owner on 2026-10-12 ("প্ল্যানের ফাইল কমিট করে কাজ শুরু করে এক এক করে শেষ কর, টেস্ট যোগ করবে ক্রিটিকাল ক্যালকুলেশনে"). Build in phases G1 → G6, one at a time, with tests on every critical calculation (dates, snapshots, money).** Source of the rules: owner's messages of 2026-10-12. **Q1–Q4 answered by the owner on 2026-10-12** (see §9); Q5–Q9 proceed on the stated defaults unless the owner objects. Build log: §10.

## 1. Owner's rules (as understood)

| # | Rule |
|---|---|
| R1 | A **group** is the unit investors join. Its projects are Project-1, 2, 3 … of that group (Group-1 → P1, P2, P3; Group-2 → P1, P2, P3). |
| R2 | A group is **Open** (members can be added), **Running** (no new members) or **Closed** (finished; no new members). |
| R3 | The date a group goes Running is the anchor. Every **calendar 2 months** from then the current project **closes** (shown complete, ready for settlement) and the **next project starts automatically** on that date. A group keeps producing projects for as long as it is active. |
| R4 | Membership is frozen per project when it starts: members and amounts at that moment are the project's investors (owner: "ঠিকাছে"). Later withdrawals or additions never change a running or settled project. |
| R5 | New money never joins a running group. An investor who invests again (top-up / reinvest) is placed in the **next project of another, open group** as a new membership. Example: ৳3 lakh in Group-1, later ৳10 lakh in a newer group. So one investor can belong to several groups, each with its own amount. |
| R6 | Settlement stays per project, manual for now: staff pay, then **upload a payment screenshot** in the admin; the investor sees it in the portal. Automatic bank payout comes later (blocked on the BEFTN template). |
| R7 | Portal: when a project cycle finishes, the investor's account shows a **progress bar at 100% — "Project-2 completed"** and a **congratulation message** goes to their mobile. After settlement the project is closed, **disappears from the dashboard and is kept on the Payouts page**. |
| R8 | Today's 5 projects each become their own group: project 1's investors → Group-1, project 2's investors → Group-2, … (see open question Q1 for numbering). |

## 2. What exists today (verified in code)

- No group concept. `InvestmentProject` is a flat company-wide list; code `NN/YYYY-INV-NN`.
- `Investment` = one investor's stake in one project. It carries the deed, witnesses, security instruments, receiving voucher and the withdrawal flow. Any investor can be added to any project; no filter.
- `SettlementService` reads the project's active investments plus `company_contribution_amount` as the capital base. Payouts are created per investor per project.
- Reinvest (`CycleElectionService`) and top-up (`InvestorRequestService`) both target "any open/running project".
- Portal dashboard and "My investments" are one card per investment, grouped by project. Payouts page lists settlement payouts. **There is no payment-proof upload** on a payout (only `payment_reference`).
- Project status values already include `closed`; it is free to mean "cycle complete, ready for settlement".

## 3. Design

### 3.1 Principle: leave the settlement engine alone

The group is a layer **above** the project. A project still owns its `investments` rows; those rows are now the **per-project snapshot** of the group's members. Settlement, payouts, batches, reports and the ledger keep reading `project->investments`. So the money maths, batches (Phase 5) and signed reports do not change.

### 3.2 New tables

**`investor_groups`** (company-owned: `BelongsToCompany` + `CompanyScope`; add to `MultiCompanyIsolationTest`)
- `company_id`, `group_code` (e.g. `G1`, unique per company), `name`
- `status`: `open` | `running` | `closed`
- `opened_on`, `running_since` (anchor date), `closed_on`, `close_reason`
- `cycle_months` (default 2; owner chose calendar months, 2026-10-12)
- `auto_cycle` (bool): the scheduler only creates projects for groups with this on
- defaults copied into each new project: `investor_share_percent`, `channel_partner_share_percent`, `company_share_percent`, `receiving_fund_source_id`, `payout_fund_source_id`
- `notes`

**`investor_group_members`** (the deposit / contract: one row per deposit)
- `company_id`, `group_id`, `investor_id`, `amount`, `invested_at`, `payment_method`, `payment_reference`, `received_by`, `voucher_id`
- `status`: `active` | `withdrawn`, `withdrawn_at`, `withdrawal_voucher_id`
- Deed, witnesses, security instruments and documents attach here (see Q5).
- Rows can be added only while the group is `open` (model-level guard, not just a hidden button).

### 3.3 Changes to existing tables

- `investment_projects`: add `group_id` (nullable at first for the backfill, required for new projects), `sequence_in_group` (1, 2, 3 …), `cycle_starts_on`.
  - Unique `(company_id, group_id, sequence_in_group)`. Name the index explicitly (MySQL 64-character limit, `MigrationIdentifierLengthTest`).
  - Project code becomes `G1-P03` for new projects. Existing codes are left untouched.
- `investments`: add `group_member_id` (nullable): "this snapshot row came from that deposit".
  - Snapshot rows **book no receiving voucher** (the money was booked once, when the member joined the group). Guard in `InvestmentLedgerService::recordInvestmentReceived`.
- `settlement_payouts`: add `payment_proof_path` (private disk), `proof_uploaded_by`, `proof_uploaded_at`. Same on `channel_partner_payouts` for symmetry.

### 3.4 Lifecycle

```
Group: Open ── start ──▶ Running ── close ──▶ Closed
        │ add members       │ day D: P1 starts          │ no more projects
        │ (voucher per      │ day D+60: P1 → "closed"   │
        │  deposit)         │          P2 starts        │
        │                   │ day D+120: P2 → "closed"… │
```

- **Dates (calendar months).** Project N starts on `running_since + (N-1) × cycle_months` and ends on `running_since + N × cycle_months`, always computed from the **anchor**, never from the previous end, so a 31st anchor does not drift (31 Aug → 31 Oct → 31 Dec → 28/29 Feb). Month-end days use Carbon's no-overflow rule.
- **Return maths keep the 60-day basis.** Projects are created with `duration_type = 2_month`, which already forces `trade_cycle_days = 60`, so annualized return stays `pool ÷ base × 360 ÷ 60 × 100` and matches the signed sheets (21.08%, 23.25%). Only the dates follow the calendar; a 59- or 62-day cycle does not change the annualized formula. *If the owner wants the real day-count there, that is a separate decision.*
- **Start group** (admin action, permission-gated): sets `running_since` (default today, editable), freezes membership, creates **Project 1** (status `running`, start = anchor, end = anchor + 2 months) and snapshots every active member into `investments`.
- **Cycle command** `investments:advance-groups` (daily, loops per company, sets/clears `CompanyContext`, idempotent, wrapped in `ModuleGuard`). For each running group with `auto_cycle` and a current project whose end date has arrived:
  1. current project → `closed` (complete, ready for Calculate & Settle);
  2. snapshot the **currently active** members into a new project N+1 (`running`, start = previous end date, end = anchor + (N+1) × `cycle_months`), inheriting the group defaults;
  3. record a "cycle completed" event for the notification (§3.7);
  4. never creates two projects for one date; never backfills more than one cycle without staff confirmation.
- **Company contribution** is per project, entered by staff once the landed cost is known (as today). Not copied forward, because it changes each cycle (Project-3: ৳9,23,987).
- **Pre-settlement check** (offered earlier, now part of this work): warn when `capital base ≠ landed cost`, since the investor's profit-per-lakh depends on it.
- **Close group**: allowed only when no project is `running` or awaiting settlement; returns remaining principal through the normal withdrawal flow.

### 3.5 Withdrawal, top-up, reinvest

- **Withdrawal from a group**: the existing 60-day written notice. The member leaves at the first cycle boundary after the notice period ends; they are simply not copied into the next snapshot. The principal goes back with the closing project's payout and the group-level withdrawal voucher is booked then. Past and running projects are untouched. (Assumption Q6.)
- **Top-up / new investment** (portal request or admin): creates a **new member deposit in an open group**, never in a running one. If the investor is already in Group-1, the new deposit goes to e.g. Group-2. `InvestorRequestService` and the portal top-up form list **open groups**, not projects.
- **Reinvest of a payout** (`CycleElectionService`): "next project" becomes **an open group** (the deposit is created there). Partial reinvest works as now. There must be at least one open group, otherwise the choice is withdrawal only.

### 3.6 Settlement and payment proof

- Calculate & Settle is unchanged, on the `closed` project.
- **Mark as paid** (single payout and payout batch) gains an optional-then-required **payment screenshot** upload. Stored on a private disk; the investor downloads it through an authorised portal route (same pattern as portal documents). A voucher/ledger entry works as now.
- Later, automatic settlement replaces the manual step; the proof field then holds the bank reference/receipt instead.

### 3.7 Investor portal

- **Dashboard**: one card per active membership, grouped by group: group name, the investor's amount, current project number, a **progress bar** (days elapsed ÷ cycle days), expected end date. When the cycle ends the same card shows **100% — "Project N completed · settlement pending"**.
- When the project's settlement is done the card **leaves the dashboard** and the full result (rate per lakh, profit, payment date, **payment screenshot**) lives on the **Payouts** page, forever. The dashboard keeps a "latest payment" line. (Owner, 2026-10-12: the project leaves the dashboard once the investor's payout is marked paid **and the screenshot is uploaded**; until then it stays at 100% / "payment on the way".)
- **My investments**: grouped by group, then projects; Statement keeps its per-event rows with the group label added.
- Admin: Investor Groups resource (list, create, view, relation managers for Members and Projects, filters by status), group filter on Investors / Projects / Settlements, group name and `P3` shown in reports.

### 3.8 Notifications (ties into Phase 6)

- The cycle command raises a "project completed" event per member. The congratulation message (SMS first; WhatsApp when its templates are approved) is sent by the Phase 6 sender.
- Message text and channel are the owner's to approve (Q7). Nothing is sent until then; the event is still logged.

## 4. Migration of today's data (no deletion, no recomputation)

1. Create one group per existing project, in the owner's order: project 1 → `G1`, project 2 → `G2`, … Each is **Project 1 (P1)** of its own group (owner, 2026-10-12). Group name/code `G1`…`G5`.
2. Each group's members = that project's investments (copy amount, date, payment fields, voucher; keep the original `investments` rows as the project's snapshot with `group_member_id` pointing at the new member row, **no new vouchers**).
3. The existing project joins its group with `sequence_in_group = 1`; status and any settlement stay exactly as they are.
4. Group status: `running` with `running_since` = the project's start date, **`auto_cycle` off**. Staff switch it on after checking dates, so the scheduler never creates a pile of catch-up projects from an old date.
5. Dry-run command first (`--dry-run` prints the mapping and the totals; a total mismatch aborts), then the real run in one transaction. Never run against non-demo data without the owner's go-ahead; tests use their own `RefreshDatabase` records only.

## 5. Build phases

| Phase | Scope | Notes |
|---|---|---|
| G1 | Tables, models, backfill command, Investor Groups admin (Members + Projects relation managers), start/close actions, group filter, new project-code format, block manual investments on grouped projects | Tests: guards, backfill, isolation |
| G2 | Snapshot service + daily cycle command, `closed` handoff, capital-base vs landed-cost warning | Tests: boundary dates, idempotency, withdrawn member omitted, anchor on 31st |
| G3 | Top-up / reinvest → open group; group-level withdrawal at boundary; import service targets a group | Tests: no deposit into a running group |
| G4 | Payment screenshot on payouts (admin + batch) + portal download | Private disk, authorised route |
| G5 | Portal: group cards, progress bar, completed state, disappear-after-settlement, Payouts page history | Browser check of the dashboard |
| G6 | Congratulation message trigger → Phase 6 sender | Needs Q7 texts |
| Later | Auto settlement / bank payout | Needs BEFTN template (P10) |

Every phase: CHANGELOG + UPDATE_NOTES entries, bilingual keys (`__()` in `lang/en.json` and `lang/bn.json`), the full `php artisan test`, and `npm run build` when CSS changes. No commit or push without the owner's approval.

## 6. Risks to watch

- **Double booking of money**: snapshot rows must never create a second receiving voucher; the group deposit is the only one.
- **Settled history must not move**: cycle command and group edits may never touch a `settled` project or its snapshot.
- **Date maths**: dates come from the anchor in calendar months; test anchors on the 28th–31st, a leap February, and a command missed for several days (it must create at most one project per run and flag the rest for staff).
- **The pool/Phase 7 engine** (owner's earlier "পুল" draft) is still blocked on D1–D10; this plan does not depend on it.

## 7. Out of scope

Automatic bank payout, the NCC BEFTN file, the profit-pool engine (Phase 7), and any per-investor day proration (an investor's share stays proportional to the amount in the frozen snapshot).

## 8. Bangla strings

New admin and portal strings go through `__()` in both language files; uncertain translations go under "Needs Bangla review" in `UPDATE_NOTES.md`.

## 9. Owner's answers and what is still open

**Answered by the owner (2026-10-12):**

- **Q1. Numbering.** Each of today's 5 projects becomes **P1 of its own group** (project 1 → G1 … project 5 → G5). Later phases of each group are created by the system. *Note kept for the record:* Project-01 had ৳53 lakh from investors and the Project-03 invoice shows ৳62 lakh, so the migration copies each project's own investor amounts as they are in the database and never recomputes them.
- **Q2. New money.** Any new investment (top-up or reinvested profit) becomes a **new membership in an open group**. The money already in the old group stays there. With no open group, the investor can only choose withdrawal.
- **Q3. When a project leaves the investor dashboard.** After the payout is **paid and the payment screenshot is uploaded**. It then lives on the Payouts page.
- **Q4. Cycle length.** **Calendar 2 months** from the date the group went Running (not 60 days). The annualized-return maths still use the 2-month = 60-day basis (see §3.4), so signed sheets keep matching.

**Defaults I proceed on unless the owner objects:**

- **Q5.** Deed, witnesses, cheque and stamp are recorded **once when the member joins the group**, not per project.
- **Q6.** Withdrawal: after the 60-day notice the member leaves at the **first cycle boundary**; the principal returns together with that project's payout.
- **Q7.** The congratulation message goes by SMS first. Draft: "অভিনন্দন! আপনার গ্রুপ-১-এর প্রজেক্ট-২ সফলভাবে সম্পন্ন হয়েছে। লাভের হিসাব শীঘ্রই জানানো হবে।" The owner finalises the wording; nothing is sent before that.
- **Q8.** The company contribution is entered by staff on each project (as today) and is **not** copied to the next phase.
- **Q9 (new).** Annualized return stays on the 2-month = 60-day formula even though project dates follow the calendar. *Say so if you want the real day count instead.*

## 10. Build log

**G1 — built 2026-10-12** (groups foundation). Where the build differs from the plan:

- `investment_projects.cycle_starts_on` was not added: `start_date` / `end_date` already carry it.
- The backfill skips a project that is still `open` and a project with no investments, and lists them. A group has no project while it collects money, so an `open` project needs the owner's decision.
- Group-member deposits can be withdrawn while the group is Open (`InvestmentWithdrawalService::withdrawGroupMember`). After it runs, the old project-level Withdraw on the member's latest project row also marks the member withdrawn. Withdrawal at the cycle boundary with the 60-day notice is G3.
- Deed, witnesses and cheque/stamp entry (Q5) still live on the investment record. Recording them once at the group level needs a change to those tables; planned with G3.
- Closing a group needs every project settled and every member out.
- The pick-a-project screens (investor draft, import, portal top-up, reinvest) hide group projects. **The backfill must not run on real data before G3 gives them an open-group target.**
- Tests: `GroupCycleCalendarTest` (dates, 28th–31st anchors, leap February, boundary days, progress) and `InvestorGroupTest` (lifecycle, one voucher, snapshot, guards, the signed-invoice figures, backfill, admin screens).

**G2 — built 2026-10-12** (cycle). Where the build differs from the plan:

- Boundaries come from the calendar (anchor + N × 2 months), not from each project's recorded `end_date`; see `UPDATE_NOTES.md` for the effect on migrated groups.
- The command runs hourly instead of daily so every company's own midnight is caught; it is idempotent.
- A group that is behind is not caught up automatically: the finished project closes, nothing starts, and the group page offers **Start the next project now** (one project per confirmation).
- The capital-base vs landed-cost warning is on the project page and in the Calculate & Settle dialog, as a warning only.
- The "project completed" event exists (`InvestorGroupProjectCompleted`); nothing listens until G6.

**G3 — built 2026-10-12** (new money, leaving, what a group pays). Where the build differs from the plan:

- **New rule decided during the build (owner to confirm):** a member who stays in the group is paid the **profit only** each project (the principal carries on); a member who leaves is paid principal + profit. The plan only said "principal returns with the closing project's payout" for leavers. One code block, `SettlementService::principalLeaving()`.
- Leaving is applied by the cycle at the project's end date (inclusive of a notice that ends that day), not by a separate withdrawal voucher: the final payout carries the principal.
- A loss borne by investors is refused on a group project until the owner decides how a member's stake is reduced.
- Deed paperwork (Q5): stamp / cheque from a draft ride on the member and become the first project row's security instrument; everything else is entered on that first row by staff.
- Open for G5: the portal statement and "My investments" still show one deposit per project row.

**G4 — built 2026-10-12** (payment screenshot). Where the build differs from the plan:

- No new columns: the screenshot is an `InvestmentDocument` (`payment_proof`) attached to the payout, which already has private storage, the admin download, the portal download scope and the audit. The `payment_proof_path` / `proof_uploaded_*` columns of §3.3 are not needed.
- The upload is optional when marking paid and can be added or replaced afterwards; the dashboard rule (paid + screenshot) is G5.

**G5 — built 2026-10-12** (portal). Where the build differs from the plan:

- The dashboard is built by `InvestorPortalCards`: one card per project under its group; a card leaves when its payout is paid **and** has a screenshot; withdrawn rows are hidden from the dashboard.
- The portal totals are membership-based (see `UPDATE_NOTES.md`): a member's money is counted once, not once per project copy.
- Old payouts made before the screenshot existed keep their card as "Paid" until a screenshot is uploaded (literal reading of the owner's rule); date cut-off offered to the owner.
- Checked in a browser on a disposable database.

**G6 — built 2026-10-12** (congratulation SMS). Where the build differs from the plan:

- The sender is the existing SMS gateway through a listener on `InvestorGroupProjectCompleted` (no separate Phase 6 sender exists yet). The message text is a company setting that starts empty (the owner's wording, Q7); nothing is sent until the owner writes it. WhatsApp is not used.

## 11. Open questions for the owner (collected at the end of the build)

1. **What a group project pays (decided by me, please confirm):** members who stay get the **profit only** (the principal carries on into the next project); a member who leaves gets principal + profit. See G3.
2. **A loss that the investors bear** cannot be settled on a group project yet: how should a member's stake be reduced?
3. **Payouts paid before the screenshot existed** (the old Project-01 etc.): the card stays on the dashboard until a screenshot is uploaded. Hide payouts paid before a date instead?
4. **The five old projects:** their payouts include principal + profit. After the migration their members are active in the group and will be copied into Project 2. Is that right for each group (did the investors really leave their principal in)?
5. **Open project:** a project still in status Open is skipped by the migration; what is it?
6. **The congratulation SMS wording** (Settings → Messages). Nothing is sent until it is written.
7. **Calendar vs the old end dates:** the cycle follows the calendar from each group's start date. Compare the old projects' end dates before switching Automatic cycles on.
