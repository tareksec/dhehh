# ⓘ Instruction Icons — A to Z Plan

> **বাংলা সারসংক্ষেপ:** ইআরপির প্রতিটি টাইটেল, সাবটাইটেল ও ফিল্ডের নিচে যে নির্দেশনা (ইনস্ট্রাকশন কমেন্ট) ছাপা আছে, সেগুলো এখন **ⓘ (i) আইকনের ভেতরে** যাবে। আইকনের ওপর মাউস নিলে (মোবাইলে ট্যাপ করলে) লেখাটা দেখা যাবে — ঠিক যেমন Ads → Meta Ad Accounts পেজের ফিল্ডগুলোতে আছে। লেখাটা বাংলা ও ইংরেজি দুই ভাষাতেই থাকবে (`lang/en.json` + `lang/bn.json`) এবং প্যানেলের ভাষা অনুযায়ী বদলাবে। কাজটা **ছোট থেকে বড় ক্রমে, ধাপে ধাপে** হবে যাতে ভুল না হয়: ০) নিয়ম ও ভিত্তি (হয়ে গেছে), ১) Trade, ২) Reseller, ৩) IMS (IMS Office + Investor + অ্যাডমিনের Investments), ৪) ERP অ্যাডমিন (ব্যাচে ব্যাচে), ৫) পরিষ্কার ও তালা। প্রতিটি ধাপ নিজে নিজে ডিপ্লয়যোগ্য; ধাপ শেষে আপনি স্ক্রিনশট দেখে অনুমোদন দিলে পরের ধাপ শুরু হবে। ভুল ঠেকাতে একটা অডিট স্ক্রিপ্ট (`php scripts/info-tip-audit.php`) আর একটা গার্ড টেস্ট (`InfoTipGuardTest`) আছে: কোনো এজেন্ট নিয়ম ভুলে সাধারণ লেখা ছাপালে টেস্ট ফেইল করবে। নিয়মটা `CLAUDE.md` ও `AGENTS.md`-তে বাধ্যতামূলক করা আছে — পরবর্তী প্রতিটি নতুন কাজে এজেন্ট নির্দেশনা ⓘ আইকনের ভেতরে লিখবে।

Status: **Phase 0 built 2026-11-01** (rule, helper, audit, guard). Phases 1–5 planned, each its own deployable change. Owner request 2026-11-01 (item 14), applies to the whole system: ERP `/admin`, IMS Office `/office`, Investor portal `/investor`, Reseller portal `/portal`, Trade portal `/trade`.

---

## 0. Rules every agent follows from today

They are mirrored in `CLAUDE.md` → "Instruction text goes in an ⓘ icon" and `AGENTS.md`. They apply to **all** new work, not only to this plan's phases.

1. **Instruction text goes in an ⓘ icon.** Text that explains how to fill or use something — under a page title, a subtitle, a section heading or a field label — is written `->infoTip(__('English text'))`, never as a line of grey text (`helperText`, `description`, `hint`, a new `<p class="text-gray-500">`).
2. **Bilingual, same commit.** The text goes through `__()` and into both `lang/en.json` and `lang/bn.json` with identical `:placeholders`. No Bangla typed in PHP/Blade. Bangla you are unsure of: add the key with the English text as the value and list it under "Needs Bangla review" in `UPDATE_NOTES.md`.
3. **Convert, don't reword.** When an existing text moves into an ⓘ, copy it exactly. No shortening, no "improving" — wording is the owner's. Long texts stay long; only the placement changes.
4. **One mechanism.** Use the helpers in section 2. Do not add `hintAction` + help-modal, a hand-made tooltip, or a new `hintIconTooltip('...')`.
5. **Some text stays visible** (section 3). Mark it on purpose: `// info-tip:keep (reason)` on the line above or at the end of the call.
6. **The guard enforces it.** `tests/Unit/InfoTipGuardTest.php` fails if a file gets more plain instruction text than `tests/Fixtures/info-tip-baseline.json` allows, or if an `->infoTip()` text is not inside `__()`. After converting a screen run `php scripts/info-tip-audit.php --write-baseline`.
7. Before handoff, for UI work: the audit shows nothing left for the files you touched, `php artisan test --filter=InfoTip`, `--filter=TranslationParityTest`, then the full suite.

---

## 1. Current state (verified from code, 2026-11-01)

Numbers from `php scripts/info-tip-audit.php` (PHP only, no database). "Static" = a plain string or `__('plain string')`; those are what the guard counts. "Dynamic" = a closure or expression (a person decides, many are live status and stay visible). "Blocks" = `Placeholder::content()` / `Text::make()` paragraphs.

| Panel | URL | Files | Static | Dynamic | Blocks | Language switch today |
|---|---|---:|---:|---:|---:|---|
| Trade portal | `/trade` | 9 | 22 | 1 | 0 | yes (Bangla first) |
| Reseller portal | `/portal` | 8 | 13 | 12 | 0 | yes |
| IMS Office | `/office` | 3 | 4 | 1 | 2 | yes |
| Investor portal | `/investor` | 5 | 5 | 4 | 4 | yes |
| ERP admin | `/admin` | 102 | 517 | 62 | 35 | **no — English only** |
| **Total** | | 127 | **561** | 80 | 41 | |

- **Blade views** (not yet covered by the audit — Phase 2 adds it): grey instruction lines in `resources/views/filament/pages` (56 files, ~220 lines), `…/reseller` (17 files, ~82), `…/ims` (29, ~40), `…/investor` (16, ~28), `…/fraud-check` (2, ~16).
- **Length of the static texts:** median 81 characters, 90% under 175, 82 texts over 150, longest 473. They are long enough that a tooltip must wrap well (checked in Phase 1).
- **Three older patterns exist** and are unified by this plan: (a) `hintIcon()` + `hintIconTooltip('plain English')` — Ads → Meta Ad Accounts and a few more; the look the owner wants, but not translated; (b) `hintAction()` + help modal (`fieldHelp()` in `InvestmentProjectResource` and `CloudStorageSettings`) — click-only; (c) 17 texts with Bangla typed in PHP (10 files, mostly Investments), which breaks the bilingual rule.
- **ERP admin has no language switch.** Trade, Reseller, IMS Office and Investor each set the locale in their own middleware (session choice). The ERP admin panel does not, so a Bangla ⓘ there cannot be seen until Phase 4.0.

---

## 2. Target behaviour

### 2.1 What the owner sees
- A small **ⓘ** (the same outlined information-circle as Ads → Meta Ad Accounts) replaces the grey line. Hover with the mouse or focus with the keyboard: the text appears in a tooltip; on a phone it is meant to open on tap (to be confirmed on a real phone in Phase 1, section 6). Screen readers read the text (Filament adds a hidden copy).
- The text is Bangla or English following the panel's language.
- Page content shrinks: forms and section headers lose their grey lines.

### 2.2 Where the ⓘ goes (Filament default components only — `AGENTS.md`)

| Today | Becomes | How |
|---|---|---|
| Field / toggle / select `->helperText('…')` | ⓘ at the end of the label row | `->infoTip(__('…'))` (Filament hint icon) |
| Infolist `TextEntry` / `Placeholder` help | same | `->infoTip(__('…'))` |
| Section `->description('…')` (subtitle) | ⓘ at the end of the section header, before any header buttons; never toggles a collapsible section | `Section::make('Title')->infoTip(__('…'))` |
| Page `getSubheading()` that is an instruction | ⓘ right after the page title | `getHeading()` returns `InfoTip::heading(__('Title'), __('…'))` |
| Table `->description('…')` | ⓘ after the table heading | decided in the phase that meets the first one (header action or `InfoTip::heading()`), then added to this table |
| Grey paragraph in a custom Blade view | ⓘ next to the heading | `<x-info-tip :text="__('…')" />` |
| Old `hintIconTooltip('English')` | same look, translated | `->infoTip(__('English'))` |
| Old `fieldHelp()` help modal | ⓘ | `->infoTip(__('…'))`, Bangla moved to `lang/bn.json` |

A text that depends on state is a closure: `->infoTip(fn (Get $get): ?string => …)`. An empty result shows no icon.

Known limits (handled when met): a field with `->hiddenLabel()` has no label row, so no ⓘ — give it a label or keep its text visible; a Section that sets its own `->afterHeader([...])` puts `InfoTip::icon($text)` in that array.

---

## 3. What becomes ⓘ and what stays visible

**Converted (default):** every static "how to fill / what this means / example / when to use" text under a title, subtitle, section heading or field label, in all five panels.

**Stays visible** (`// info-tip:keep (reason)`, or simply not touched because the audit does not count it):

1. **Live information:** counts, totals, status, "switched off in settings", validation errors, anything built from the record or the form state (closures that report state). Closures that are *instructions* (for example a different help line per option) are converted.
2. **Row data:** `TextColumn::description()` (the SKU under a product name), stat captions, action descriptions, callouts and notices (`Callout`, notifications).
3. **Dialogs and empty tables:** confirmation text in modals (`modalDescription`) and empty-state messages — the user must read them before acting. *Owner may change this — see question 2.*
4. **Sign-in / welcome / invite screens** whose subheading greets a named person.
5. **Warnings that protect data or money** ("a permanent delete cannot be undone", "links you shared will stop working"). Default: keep as the short visible line and put the longer explanation in the ⓘ. *Owner decision needed — see question 1; until answered, convert them and keep the first sentence visible only where the text says "cannot be undone".*

---

## 4. Phases

Order is small to large so the pattern is proven on the smallest panel first. Each phase: **Do**, **Tests**, **Done when**. A phase ends with the owner looking at screenshots (Bangla and English, phone and desktop); the next phase starts after "approved".

### Phase 0 — Rule and foundation (built 2026-11-01)
**Done**
- 0.1 Rule in `CLAUDE.md` and `AGENTS.md`.
- 0.2 `App\Support\InfoTip` (registered in `AppServiceProvider`): `->infoTip()` on fields, infolist entries/placeholders and sections; `InfoTip::icon()`, `InfoTip::html()`, `InfoTip::heading()`; Blade component `<x-info-tip>`.
- 0.3 `App\Support\InfoTipAudit` + `php scripts/info-tip-audit.php` (summary per panel, `--panel=`, `--file=`, `--dynamic`, `--blocks`, `--json`, `--write-baseline`).
- 0.4 `tests/Unit/InfoTipAuditTest.php` (the scanner), `tests/Unit/InfoTipGuardTest.php` (the ratchet and the `__()` check), `tests/Feature/InfoTipTest.php` (the Filament and Blade helpers), baseline `tests/Fixtures/info-tip-baseline.json` (561 texts).
- 0.5 This plan, `CHANGELOG.md`, `UPDATE_NOTES.md`, `docs/codebase-map/core-platform.md`.

**Note:** nothing on a screen changes in Phase 0 — no existing text was moved yet.

### Phase 1 — Trade portal `/trade` (pilot, 9 files, 22 texts)
**Do**
1.1 `php scripts/info-tip-audit.php --panel=trade --dynamic --blocks`; classify each line (section 3). Files: `BusinessProfile`, `CashBook`, `GoodsInResource`, `MoneyAccountResource`, `LcFileResource`, `LandedCost`, `ViewLcFile`, `DeliveryChallanResource` (plain texts) and `DueResource` (one conditional text). The page subheadings of `ListDues` and `LandedCost` show live totals and an LC number, so they stay.
1.2 Convert with the procedure in section 5; add missing keys to both lang files (most Trade texts already use `__()`).
1.3 **Look and feel check — the gate for everything after.** Playwright, light/dark, 1366 px and 360 px, Bangla and English: hover on desktop, tap on a touch device, keyboard focus. The longest text must wrap inside the screen; if the default tooltip width is wrong, fix it once in the panel theme CSS.
1.4 Check that a hidden-label field, a collapsible section and a section with header buttons behave (section 2.2 limits).
**Tests:** a Livewire/Filament render test per converted page that asserts the tooltip text is present and the old grey line is gone; `InfoTipGuardTest` (baseline lowered).
**Done when:** the Trade screens show no grey instruction lines, every text is in the ⓘ in both languages, and the owner approves the look on a phone.

### Phase 2 — Reseller (13 + 12 dynamic, plus Blade)
2.1 Extend the audit to Blade views (`resources/views/filament/**`: grey instruction paragraphs) and add the list to this plan. Baseline for Blade, same ratchet.
2.2 **Reseller portal `/portal`:** 8 PHP files (`MyStore` is the biggest) and `resources/views/filament/reseller` (17 files). Dynamic texts: instructions converted, live balances/status kept.
2.3 **Admin side of the reseller programme** (ERP, Resellers cluster): `ResellerLevels`, `Resellers`, `ResellerCommissions`, `ResellerDeposits`, `ResellerPayoutRequests`, `ResellerVariationPrices` and the `ResellerSettings` page.
**Tests / Done when:** as Phase 1, for the portal pages the reseller sees.

### Phase 3 — IMS (investments)
3.1 **IMS Office `/office`:** 3 files + `resources/views/filament/ims` (29 files).
3.2 **Investor portal `/investor`:** 5 files + `resources/views/filament/investor` (16 files). Sign-in/invite subheadings stay (section 3.4).
3.3 **Admin Investments cluster** (`/admin`): `InvestorGroups`, `Investors`, `InvestorDrafts`, `InvestmentProjects`, `ProjectSettlements`, `InvestmentRecords`, `ChannelPartners`, `InvestorRequests`, `AgreementTemplates`, `PayoutBatches`, `ExchangeRates` (the eleven resources of the Investments cluster, ~45 texts).
3.4 Replace the two `fieldHelp()` help-modals (click-only) with ⓘ; move the 17 Bangla texts typed in PHP into `lang/bn.json` (English text becomes the key) — a Bangla review item for the owner.
**Done when:** every IMS screen in all three places reads in the ⓘ, and no Bangla is typed in the Investments PHP.

### Phase 4 — ERP admin `/admin` (517 texts, 102 files), in batches
4.0 **Admin language switch** (precondition: `docs/i18n-bangla-english-plan.md` Phase 7.1, owner decision — question 3). Same approach as the other panels (a session-based choice from the user menu, English stays the default), so a Bangla ⓘ can be seen in the ERP. Batches can be converted before this, but the owner only sees Bangla after it.
Batches (each its own change; file lists come from the audit):
- 4a **Settings and tool pages** — `app/Filament/Pages` (157 texts in 23 files; split by file, ≤ 40 texts per change).
- 4b **Storefront cluster** — `StorefrontSettingResource` (117 texts in 2 files; split by tab), slides, pages, payment methods, carousels, offers, categories (~170).
- 4c **Sales, orders, couriers** — `Orders`, `CourierProviders`, quotations, customers, bookings, blacklists (~45).
- 4d **Catalog, inventory, purchasing** — `Products`, `Purchases`, `Suppliers`, `StockPools`, stock movements, media (~45).
- 4e **Accounts and finance** — vouchers, expenses, `ExpenseScans`, customer payments, `FundSources` (~15).
- 4f **CRM, ads, AI, company** — `MetaAdAccounts` (its 5 old tooltips become `infoTip`), `Broadcasts`, `ConversationChannels`, quick replies, knowledge, `Companies`, users, documentation (~55).
- 4g **Blade pages and widgets** — `resources/views/filament/pages` (56 files), widgets, table descriptions, the remaining dynamic items.
**Tests / Done when:** as Phase 1 per batch; the audit shows 0 for the ERP.

### Phase 5 — Clean-up and lock
5.1 Review every remaining dynamic and block item once more; convert or mark `info-tip:keep`.
5.2 Delete the old helpers (`fieldHelp()`, help-modal code) once unused.
5.3 Baseline reaches 0: remove `info-tip-baseline.json` and make `InfoTipGuardTest` strict (any plain instruction fails).
5.4 `PROJECT_GUIDE.md`: the ⓘ convention and how to verify it.
5.5 Owner walk-through of each panel in Bangla and English; the "Needs Bangla review" keys from all phases are settled in `lang/bn.json`.
**Done when:** the guard is strict and green, and the owner signs off.

---

## 5. How to convert one file (the step-by-step that prevents mistakes)

1. **List:** `php scripts/info-tip-audit.php --file=<path> --dynamic --blocks`.
2. **Decide each line** with section 3: convert, keep (`// info-tip:keep (reason)`), or leave (not an instruction).
3. **Convert:** `->helperText(X)` → `->infoTip(X)`; `Section … ->description(X)` → `->infoTip(X)`; wrap plain English in `__()`. The text is copied unchanged.
4. **Keys:** every new `__('…')` key goes into `lang/en.json` and `lang/bn.json` (same `:placeholders`); uncertain Bangla → English text as the value and a line under "Needs Bangla review".
5. **Check the limits** in 2.2: label hidden? own `afterHeader`? a text that was the only content of its section?
6. **Run:** the audit shows nothing left for the file; `php artisan test --filter=InfoTip`, `--filter=TranslationParityTest`, the tests of the pages touched, then the full suite.
7. **Look at it:** screenshots at 1366 px and 360 px, hover and tap, Bangla and English (Playwright). No horizontal scroll.
8. **Lower the baseline** (`php scripts/info-tip-audit.php --write-baseline`), update `CHANGELOG.md`, `UPDATE_NOTES.md`, the map file; commit; push to the feature branch.

---

## 6. Risks and how they are handled

| Risk | Handling |
|---|---|
| Phones have no hover | Filament's tooltip opens on tap; checked on a touch device in Phase 1 (the gate). |
| Very long tooltips (longest 473 characters) | Checked in Phase 1; one theme CSS rule if needed. Texts are never shortened without the owner. |
| An ⓘ hides a rule the user must read | Section 3.5 and question 1. |
| Field without a label row | Limit in 2.2; found by the render test. |
| A converted Section loses its header buttons | The macro keeps `headerActions()`; covered by a test in Phase 1. |
| New work reintroduces grey text | `InfoTipGuardTest` + the rule in `CLAUDE.md`/`AGENTS.md`. |
| Bangla missing in the ERP | Phase 4.0 (language switch). |
| Mistakes while converting 561 texts by hand | Small phases, the audit lists exactly what is left, the guard only lets numbers go down, owner approves screenshots between phases. |

---

## 7. Checklist for any PR that touches a screen (copy into the PR description)

```
ⓘ checklist
- [ ] No new helperText/description/hint with plain instruction text (infoTip(__('…')) instead)
- [ ] Text copied unchanged when moved; keys in lang/en.json AND lang/bn.json
- [ ] Anything left visible is marked // info-tip:keep (reason)
- [ ] php scripts/info-tip-audit.php: nothing left in the files touched; baseline lowered
- [ ] InfoTipGuardTest, TranslationParityTest green
- [ ] Looked at in Bangla and English, 1366 px and 360 px, hover and tap
```

## 8. Open questions for the owner

1. **Warnings that protect data or money** ("cannot be undone", "links you shared will stop working"): inside the ⓘ like everything else, or keep the short warning visible and put only the explanation in the ⓘ? (Default until answered: visible first sentence only for "cannot be undone"-type warnings.)
2. **Modal confirmations and empty-table messages:** keep visible (default) or also move into an ⓘ?
3. **ERP admin in Bangla (Phase 4.0):** switch from the user menu, English default — okay? (It is the optional Phase 7.1 of the i18n plan.)
4. **"Trade Dashboard"** was taken to mean the Trade portal at `/trade`. Correct?
5. The **Investor portal** and the **admin Investments** screens are included under IMS (Phase 3). Correct?
