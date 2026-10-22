# 16 — AI Inbox Control Plane: Implementation Plan

> **বাংলা সারসংক্ষেপ:** AiPraktor (aipraktor.com) ড্যাশবোর্ডের প্রতিটা পেজ ২০২৬-১০-০৯ তারিখে পড়ে দেখা হয়েছে, এবং `origin/main` (v2.64.1)-এর কোডের সাথে মেলানো হয়েছে। আমাদের ERP-তে AI চ্যাটের ভিত্তি আগে থেকেই আছে: Inbox, Messenger/WhatsApp, AI auto-reply (প্রোডাক্ট/FAQ/ডেলিভারি টুল), ছবি ও ভয়েস বোঝা, Leads, Follow-ups, Broadcasts, Orders, কুরিয়ার। যা নেই তা হলো একটা পরিষ্কার "কন্ট্রোল প্লেন"। এর মানে: চ্যাট AI-এর হাতে না মানুষের হাতে, সেটা টেকসইভাবে রাখা ও লগ করা; কোনো হ্যান্ডওভার যেন হারিয়ে না যায়; AI কতটা কাজ করছে তা মাপা; আর মালিক নিজেই AI-এর নির্দেশনা বদলে, পরীক্ষা করে, প্রকাশ করতে পারেন। কাজ ৮টা ধাপে ভাগ করা। প্রথম ধাপে AI/Human মোড ও Activity লগ আসবে। এই ধাপ `docs/CRM_AI_SALES_AUDIT_2026-09-09.md`-এর High সমস্যাগুলোও ঠিক করে। AiPraktor-এর সংখ্যাগুলো (৩ ঘণ্টা, ২৩ ঘণ্টা ইত্যাদি) শুধু রেফারেন্স। আমাদের নিয়ম মালিক §৩-এ ঠিক করবেন।

Status: **Phases 1–3 built (2026-10-09) and committed on branch `feature/ai-inbox-control-plane` (not merged). Phase 4 built 2026-10-10 and committed on the same branch. Phase 5 built 2026-10-16 and the checklist part of phase 8 built the same day. Phases 6 and 7 built 2026-10-19 after the owner answered O2–O9. Phases 5–7 and the checklist were committed on the same branch on 2026-10-19 (still not merged to main). Practice mode (O7) was declined on 2026-10-19 and asked for again on 2026-10-20; with it, "Seen"/typing and the Messenger product cards built in phase 9, the plan is complete. Phases 1-9 are committed and merged to main.** Owner instruction for O1 and the reply style: "aipraktor এর মত করে বিল্ড কর" — follow AiPraktor. Other phases still wait for the owner answers in §3.

Source: a read-only walk through every AiPraktor dashboard page (owner's practice account "Bright Hive Digital", 2026-10-09; no page connected, so every list was empty — behaviour is taken from each page's own text and controls, not from exercising them). Code facts are from `origin/main` at `9c54cfe8` (v2.64.1).

---

## 1. Starting point

### 1.1 What already exists (verified on `origin/main`, 2026-10-09)

| Area | Today | Gap against AiPraktor |
|---|---|---|
| Channels | `ConversationChannel` (messenger / whatsapp, cloud_api or app connection, health diagnostics) | No web-chat channel; no per-channel "AI on/off" switch shown as a page card |
| Conversations | `Conversation`: `status` open/pending/closed, `ai_enabled`, `human_handled_until`, `assigned_to`, 30 s `HUMAN_PRESENCE_SECONDS` | No explicit automation mode, no scheduled revert, no history of mode changes |
| AI reply | `AiReplyService` + `AiAutoReplyJob`: tool loop (`lookup_product`, `lookup_faq`, `lookup_delivery_charge`, order link), vision, voice, budget caps, `escalate()` | Escalation only sets `pending` + notifies; AI can answer again (audit High #2) |
| AI runs | `CrmAiRun`: per-run tokens and `estimated_cost_usd` | No statement/CSV view for the owner |
| AI settings | `AiSettingsService`: one `brand_voice` + one `sales_guidelines` text, `review_mode` | No sectioned instructions, no draft → test → publish → rollback, no "questions the AI could not answer" |
| Facts | `CompanyFaq` (keyword match) | No document knowledge base. The new `DocumentationArticle` module (main, v2.6x) is **global app help, not company-scoped**, so it cannot hold a company's business facts |
| Follow-ups | `SalesFollowUpService`, `CrmSalesFollowUp`, `SalesFollowUps` page | No hot/warm judgement, no recorded skip reason, no Meta 24 h window check |
| Broadcasts | `BroadcastService`: WhatsApp template + SMS to leads/customers | No Messenger audience limited to the 24 h window; no mandatory test-to-self |
| Notifications | `BusinessNotificationService`, push (`PushDevice`), `ai-handoff` alert | No persistent attention queue that stays until a person marks it done |
| Metrics | `CrmSalesMetrics` widget | No AI-handled %, first-response time, SLA breach |
| Orders | Orders, Customers, `CourierBookings`, `CourierMerchantDashboard`, Fraud Checker (main), stock-shortage popup, Upsell tag, reseller and Next.js sources | No invoice QR scan → bulk move; no daily courier handover sheet; no single "to chase" list |
| Storefronts | Blade storefront + Next.js storefront API (main) | No embeddable chat widget on either |

### 1.2 AiPraktor pages in short (what each does)

| Page | Behaviour (from the page text) |
|---|---|
| Overview | Setup checklist (8 steps, progress, time per step); KPI tiles: conversations, handled by AI %, waiting on a human, follow-ups due, pipeline value; live queue; per-page cards; live activity; traffic & routing (AI / Human / Reverting split, avg and median first reply, % answered late) |
| Inbox | Views Waiting / Unread / Open / Done / All, "longest wait first", keyboard nav (↑↓, Enter, E = done, / = search), customer details beside the chat |
| Notifications | Persistent record of every handover, AI alert, handed-back follow-up; stays until a person marks it done; tiles: waiting, past its target (page response time), never answered (went back to AI first), done; filters by kind (handovers, AI alerts, follow-ups, calls, bookings, payments, website leads, requests, given to me) |
| Leads | Pipeline New → Contacted → Qualified → Follow-up → Won / Lost; Today / Pipeline / List / Results; assignee filter; CSV; add by hand |
| Orders | Statuses Pending, On Hold, Approved, Processing, Ready to Ship, In-Transit, Delivered, Flagged, Cancelled; never deleted; source filter; test orders toggle. Sub-tabs: **Customers** (one record per mobile, filters by product code, district, order count, "no order since"), **Scan** (scan invoice QR / type number → list → move all at once, camera), **Courier** (cash held by couriers, last 45 days; printable daily handover sheet per courier for the rider to sign), **To chase** (tapped Buy / became a lead / tapped an offer, no order), **Report** |
| Activity | Audit trail of every mode change from every source incl. automatic reversion; filters Dashboard / AI / Auto-revert / Bulk / Answered; CSV; streaming |
| Analytics | Handoffs, auto-reversions, avg first response, SLA breach rate, waiting now, longest wait; activity chart; current split; busiest pages; by source |
| Follow-ups | Customer judged 3 h after last message; warm → one reminder, hot → two (3 h and 20 h), never past Meta's 24 h window, never in a chat a person owns, never after they ordered or said no; skip reasons shown; on/off per page |
| Broadcasts | Short offer to everyone who wrote in the last 23 h (Meta rule); product cards / discount card; test lands on own phone first; drafts, scheduled, sent |
| Products | Catalog the AI sells from; each product image indexed once for photo matching; featured / hidden from AI / not ready / no photo / edited in panel / removed from source; sync preview before changes; CSV |
| Posts | Every FB/IG post a customer comments on is read once; photos mapped to products; "Fix" to correct a wrong match; correction remembered for the same picture later |
| Knowledge | Long-tail documents (fees, rules, packages, returns); AI looks facts up; test question box using the same lookup |
| Website chat | Support button on own site: live chat + voice call answered by the same assistant, plus WhatsApp / Messenger / phone links; one-line embed |
| Agent Desk | Owner's part of the AI instructions in blocks (About the shop, Delivery, Your voice, Your exact words, Anything else); business name + hours; describe a change in plain Bangla/English → shown diff → test on a draft → publish; publish history with rollback; list of customer questions the AI did not know (30 d) |
| Pages | One FB page (+ IG) and one WhatsApp per business; AI switch per channel; counts with AI / with team / waiting |
| Billing | Prepaid AI credit; per-request statement in Taka (tokens in/out, customers, failed/succeeded), CSV |
| Team | Teammates who switch chats AI ↔ human; invited by email; scope can be narrowed |
| This device | Per-device notifications on/off, inbox sound, install as app |
| Setup | Guided steps with "practice mode": the assistant answers only test chats until the owner goes live |

---

## 2. Scope decision

In scope (this plan): automation mode + activity, attention queue + SLA, analytics, Agent Desk, knowledge base, follow-up rules, Messenger broadcast window, order scan + courier handover sheet, to-chase list, setup checklist / go-live switch, AI usage statement.

Out of scope for now: prepaid AI credit billing (we are not reselling AI; owner pays the provider directly — revisit only if the owner wants to charge companies), post-comment product matching and the website chat widget (each is its own plan once Phases 1–4 are live), voice call on the website, feedback page.

---

## 3. Owner decisions (মালিকের সিদ্ধান্ত)

Per `CLAUDE.md`, none of these may be invented. AiPraktor's values are listed only as a reference.

| # | Question | AiPraktor reference |
|---|---|---|
| O1 | When staff take over a chat, how long before it returns to the AI automatically? Or never (manual only)? | "Reverting" state with automatic return |
| O2 | Response-time target for a waiting chat (minutes), per company or per channel? Business hours only? | "page's response time" |
| O3 | Which events go in the attention queue? (handover, AI could not answer, complaint keyword, payment proof, follow-up handed back, website lead…) | handovers, AI alerts, follow-ups, calls, bookings, payments, website leads, requests |
| O4 | Follow-up rule: quiet hours before judging; hot vs warm definition; number and timing of reminders | 3 h; hot ×2 (3 h, 20 h), warm ×1 (20 h) |
| O5 | Messenger broadcast: allowed at all? Mandatory test-to-self? Who may send? | 23 h window, test first |
| O6 | Who may publish AI instruction changes — owner only, or a role permission? | owner |
| O7 | Practice mode: should a new company's AI answer only staff test chats until someone presses "Go live"? | yes |
| O8 | Courier handover sheet: which fields must the rider sign for (parcel count, COD total, per-parcel list)? Printed per courier per day? | per courier per day |
| O9 | "To chase" sources: carts, checkout attempts, leads without order, offer clicks — which count, and for how many days? | Buy tapped, lead, offer tapped; 7/14/30 d |

### 3.1 Owner answers — 2026-10-19 (asked in chat; O1 was answered earlier)

| # | Answer | What it means for the build |
|---|---|---|
| O2 | 30 minutes, all hours (no business hours). | `response_target_minutes` = 30. No business-hours logic. |
| O3 | Add payment proof, website lead, call and booking requests, complaint words (on top of AI handovers). | New attention kinds; each needs a detector (see §9). |
| O4 | Reminders at 1 h, 6 h, 24 h and 48 h, then one per week, at most 4 weeks. Counted from the customer's last message. Same schedule for everyone (no hot/warm). Nothing is sent 22:00–08:00. Only customers who were sent a checkout link or quotation and have not ordered. | Replaces the single delay. Hot/warm `temperature` is dropped. Weekly steps are read as 7 days after the previous reminder (my reading — the owner did not say). |
| O4a | On Messenger the 24 h, 48 h and weekly steps cannot be sent (Meta 24 h window) — they go to the staff Needs attention list. | WhatsApp keeps using the approved template at every step. |
| O5 | Messenger broadcast allowed. A successful test to your own channel is mandatory first. People with the CRM manage permission send. | As designed in §4.7. |
| O6 | Same permission as Integrations (`canManageSettings()`). | Already the default; no change. |
| O7 | **No practice mode.** Turning the AI on makes it live. *(Reversed 2026-10-20: the owner asked for practice mode after all — see Phase 9.)* | ~~`ai_live` is dropped; the setup checklist stays at 8 steps.~~ Built in Phase 9: `ai_live`, nine checklist steps. |
| O8 | Handover sheet: parcel count and total COD, per-parcel list, consignment/tracking number. No rider name block. One sheet per courier per day. | As designed in §4.8, without signature fields. |
| O9 | All four sources: abandoned cart, checkout attempt, lead without order, offer tap. Days not answered — 7 / 14 / 30 filter, default 7 (my choice, easy to change). | As designed in §4.8. |
| Returns | Words "ফেরত", "রিফান্ড", "refund" look in FAQ and knowledge first; only if nothing is found the chat goes to the team. "complaint", "human", "agent" still go to the team at once. | Changes `AiReplyService` hand-over order. |

---

## 4. Technical decisions (my call; open to pushback)

### 4.1 Automation mode (fixes audit High #1, #2, #3 partly)

- New columns on `conversations`: `automation_mode` (`ai` | `human` | `reverting`), `mode_changed_at`, `revert_at` (nullable), `mode_changed_by` (nullable user id).
- `ai_enabled` stays as the per-conversation hard off-switch; `human_handled_until` is migrated into `revert_at` and then read through one accessor so nothing else breaks.
- One service, `ConversationModeService::switch(Conversation, string $mode, string $source, ?User, ?string $reason)`, is the only writer. `$source`: `dashboard`, `ai`, `auto_revert`, `bulk`, `staff_reply`.
- `AiReplyService` checks `automation_mode === 'ai'` at job start **and again immediately before dispatch** (under a conversation-level lock — audit High #4).
- `escalate()` switches to `human`, so a later inbound message no longer wakes the AI.
- Scheduled command `crm:revert-conversations` loops per company (`CompanyContext`), switches due `reverting` chats back to `ai`, source `auto_revert`.

### 4.2 Activity log

- Table `conversation_mode_events` (company-owned: `BelongsToCompany` + `CompanyScope`, added to `MultiCompanyIsolationTest`): `conversation_id`, `from_mode`, `to_mode`, `source`, `user_id`, `reason`, `created_at`.
- Filament page **Activity** under CRM: filters by source, date; CSV via `ReportExportService`.

### 4.3 Attention queue

- Table `attention_items` (company-owned): `kind`, `conversation_id` nullable, `subject_type/id` nullable, `opened_at`, `due_at` (from O2), `resolved_at`, `resolved_by`, `assigned_to`, `outcome` (`answered`, `returned_to_ai`, `dismissed`).
- Opened by `ConversationModeService` (handover), `AiReplyService::escalate()`, follow-up hand-back, etc. Closed when a person marks it done; "never answered" = closed by auto-revert with no staff message.
- Push notification stays as the alert; the queue is the record.
- Filament page **Notifications** (tabs: Needs attention / Done / All; kind filter; "given to me").

### 4.4 Analytics

- Widgets fed by `conversation_mode_events`, `attention_items` and `conversation_messages` timestamps: AI-handled %, handoffs, auto-reversions, avg / median first human response, SLA breach %, waiting now, longest wait, by channel.
- First-response timer starts at the first attention item for a chat (same rule as AiPraktor: counts from when someone could claim it).

### 4.5 Agent Desk

- Table `ai_instruction_versions` (company-owned): `blocks` JSON (`about`, `delivery`, `voice`, `exact_words`, `other`), `status` (`draft` | `published` | `superseded`), `published_at`, `published_by`, `change_note`.
- `AiReplyService::systemPrompt()` reads the latest **published** version; existing `brand_voice` / `sales_guidelines` are migrated into the first version.
- Test chat: a Livewire panel that runs `AiReplyService` in a dry-run mode (no transport send, no lead/order side effects, run logged with `is_test = true` on `CrmAiRun`). Option to test against the draft.
- Rollback = re-publish the previous version.
- "Questions the AI could not answer": list of `CrmAiRun` rows that ended in escalation with reason "no grounded answer" for the last 30 days, with a button to add an answer to Knowledge or FAQ.
- Plain-language change requests ("ফি ৩০,০০০ নয়, ২৫,০০০") are a later step; first version edits the blocks directly.

### 4.6 Knowledge base

- Table `knowledge_documents` (company-owned): `title`, `content` (Markdown), `is_active`, `source_file` nullable. Reuse the Markdown import UX from the Documentation module, not its table.
- New AI tool `lookup_knowledge(query)`: full-text search (MySQL `FULLTEXT` / SQLite `LIKE` fallback for tests) returning top passages with document ids; the reply must cite ids like the existing product/FAQ grounding. Embeddings only if search quality proves insufficient.
- Test-question box on the page uses the same tool.

### 4.7 Follow-ups and broadcasts

- `SalesFollowUpService` gains `temperature` (hot/warm per O4), a `skip_reason` column, and a Meta 24 h window guard; suppression is checked at send time (audit High #6).
- `BroadcastService` gains a Messenger audience = conversations whose last inbound message is inside the window from O5, re-checked per recipient at send time; a broadcast cannot move from draft to queued until a test send to the sender's own channel succeeded.

### 4.8 Orders: scan, handover sheet, to chase

- Invoice gets a QR code with the order number (printable HTML; no new PDF dependency — dompdf cannot shape Bangla).
- Page **Scan parcels**: input (USB scanner = keyboard) + optional camera (`BarcodeDetector` with JS fallback), builds a list, one bulk action through `OrderStatusWorkflowService` so every allowed transition and log still applies.
- Page **Courier handover sheet**: courier + Dhaka day → parcels booked from the ERP that day, COD totals, signature lines; print view. Uses existing `CourierBooking`.
- Page **To chase**: union of `StorefrontCartRecord`, `StorefrontCheckoutAttempt` (incl. `CheckoutOutcome` from main), leads with no order and offer clicks per O9, minus anyone who ordered since; "contacted" toggle.

### 4.9 Setup checklist and go-live

- Dashboard widget computing each step from data (channel connected, business facts filled, products with photos, payment rule, push device, test chat run, live). No stored progress except "dismissed".
- Company setting `ai_live` (per O7). While off, AI replies only to conversations flagged as staff tests.

### 4.10 Language

Every new string through `__()` and added to both `lang/en.json` and `lang/bn.json` (`TranslationParityTest`). Admin-entered instruction blocks and knowledge content stay admin-managed.

---

## 5. Data model (new)

| Table | Owner | Notes |
|---|---|---|
| `conversation_mode_events` | company | append-only |
| `attention_items` | company | indexed on (`company_id`, `resolved_at`, `due_at`) |
| `ai_instruction_versions` | company | one `published` per company (and per channel later) |
| `knowledge_documents` | company | FULLTEXT on `title`, `content` (MySQL) |
| `conversations` + columns | — | `automation_mode`, `mode_changed_at`, `revert_at`, `mode_changed_by` |
| `crm_ai_runs` + column | — | `is_test` |
| `crm_sales_follow_ups` + columns | — | `temperature`, `skip_reason` |

Index names are kept under MySQL's 64-character limit (see v2.64.1 fix).

---

## 6. Phases

| Phase | Content | Depends on |
|---|---|---|
| 1 | Automation mode, `ConversationModeService`, conversation-level lock + pre-send recheck, auto-revert command, Activity page | O1 |
| 2 | Attention queue + Notifications page + SLA | O2, O3 |
| 3 | Analytics widgets | 1, 2 |
| 4 | Agent Desk (versions, test chat, rollback, unanswered questions) | O6 |
| 5 | Knowledge base + `lookup_knowledge` | 4 |
| 6 | Follow-up temperature / window / skip reasons; Messenger broadcast window + test-first | O4, O5 |
| 7 | Invoice QR, Scan parcels, courier handover sheet, To chase | O8, O9 |
| 8 | Setup checklist + practice mode / go-live | O7 |

Each phase ships on its own with a CHANGELOG entry (`docs/release-policy.md`).

---

## 7. Tests (new files)

- `ConversationModeServiceTest`: every source writes an event; escalate keeps the chat human; inbound message after escalation does not dispatch AI; auto-revert returns only due chats, per company.
- `AiReplyDispatchRecheckTest`: staff takeover during inference prevents the send; two inbound messages in one chat produce one reply run at a time.
- `AttentionQueueTest`: open on handover, due time from settings, "never answered" when auto-reverted without a staff message.
- `AiInstructionVersionTest`: publish, rollback, prompt reads only published; test run sends nothing and creates no lead/order.
- `KnowledgeLookupToolTest`: grounded passages returned, inactive docs excluded, other company's docs never returned.
- `FollowUpWindowTest`, `MessengerBroadcastWindowTest`: window and opt-out checked at send time.
- `OrderScanBulkMoveTest`, `CourierHandoverSheetTest`, `ToChaseListTest`.
- Every new model added to `MultiCompanyIsolationTest::test_every_company_owned_model_uses_the_company_scope_contract`.

Run as plain `php artisan test` (never `--env=testing`).

---

## 8. Risks

- Migrating `human_handled_until` → `revert_at` must not leave live chats without a mode; backfill in the migration (`human` if the lock is in the future, else `ai`).
- A dry-run AI path that shares code with the live path must be impossible to send from; enforce at the transport layer, not only in the caller.
- Meta window rules differ by channel and message tag; the guard is per channel and errs toward not sending.
- Local `main` was 164 commits behind `origin/main` when this plan was written; sync before Phase 1.

---

## 9. Build log

### Phase 1 — 2026-10-09

Decisions taken from AiPraktor (owner: "build it like AiPraktor"), read from its home and help pages:

- O1: takeover time is a company setting, default **6 hours** (AiPraktor's default for a new page), 30–1440 minutes; Inbox timer choices 30 min / 1 hour / 6 hours / no limit. When the time is up the chat returns to the AI, whether or not anyone answered.
- Handover reasons are AiPraktor's fixed list plus `ai_error` for technical failures.
- "." typed in the page inbox switches the chat (team ↔ AI). A normal page-inbox reply keeps the chat with the team (AiPraktor does not; we keep the earlier ERP behaviour of pausing, now for the takeover time).
- Wait before answering: 0–15 s (AiPraktor 15 s); our default stays 3 s, the existing value.
- Handover notice and neat replies are opt-in toggles, because the existing design and tests send nothing on handover and use one-line replies.

Design change from §4.1: no separate `revert_at` column — `human_handled_until` already meant "held by a person until" and became the return time; `ai_enabled` is kept in step by `ConversationModeService`. The conversation-level lock and the pre-send recheck were already on main (`AiAutoReplyJob`, `AiReplyService::sendAiReply()`); the recheck now also reads `automation_mode`.

Files: migration `2026_10_09_000100_add_automation_mode_to_conversations_table`, `ConversationModeEvent`, `ConversationModeService`, `RevertConversationsToAi` (`crm:revert-conversations`), `ConversationActivity` page, `partials/inbox-ai-mode.blade.php`, changes in `AiReplyService`, `SalesReplyRenderer`, `AiSettingsService`, `AiAutoReplyJob`, `StoreIncomingMessageJob`, `ConversationMessengerService`, `Inbox`, `Integrations`. Tests: `ConversationModeTest`.

### Phase 2 — 2026-10-09

- O3 (partly): only AI handovers (every reason, including AI errors and follow-up hand-backs, which go through `escalate()`) open an item. Payment proof, website leads, calls and bookings wait for the owner's list.
- O2 (partly): one company-wide setting `response_target_minutes`, default 0 = no target; no per-channel target and no business hours yet.
- An item closes when the chat goes back to the AI (outcome `answered` if a person replied, `never_answered` if the auto-return happened with no reply, else `returned_to_ai`) or when someone presses Done (`answered` / `dismissed`). The first staff reply (dashboard or page inbox) sets `first_response_at`; the response time counts from the handover, like AiPraktor.
- Page named **Needs attention**, not "Notifications", so it is not confused with Filament's bell.

Files: migration `2026_10_09_000200_create_attention_items_table`, `AttentionItem`, `AttentionQueueService`, `NeedsAttention` page; hooks in `ConversationModeService`; setting in `AiSettingsService` / `Integrations`. Tests: `AttentionQueueTest`.

### Phase 3 — 2026-10-09

- One page, **CRM → AI Inbox Report**, with period buttons (today / 7 / 30 / 90 days) instead of dashboard widgets, so the numbers sit in one place.
- Computed on request; no summary table yet. If large companies make it slow, add a daily roll-up later.
- "Handled by the AI alone" = chats with a customer message in the period and no switch to the team in the period. First reply is measured from the handover (attention item), like AiPraktor.

Files: `AiInboxReportService`, `AiInboxReport` page, `ai-inbox-report.blade.php`. Tests: `AiInboxReportTest`.

### Phase 4 — 2026-10-10 (committed)

- **Three blocks, not five.** The agent never writes free text: `submit_reply` only selects reply keys and the server renders every fact from the catalog, FAQ and delivery tools. A "delivery" or "exact words" box could not reach a customer, so it was dropped. Blocks: `about` (new, `business_about`; background only, the prompt says it is not a source of facts), `voice` (`brand_voice`), `guidelines` (`sales_guidelines`).
- **The live text stays in the settings.** Versions are history, draft and rollback on top: publish and restore write the settings, and saving the same fields on Integrations records a version, so the prompt source is unchanged and nothing can drift.
- **Test chat = `AiReplyService::dryRun()`**: same prompt, tools, keyword hand-over, FAQ shortcut and renderer as a live reply. Read-only tools are real; the three action tools answer "test mode"; sending is blocked in `escalate()`, `sendAiReply()` and (for an unsaved conversation) `ConversationMessengerService::send()`. Test runs are `crm_ai_runs.is_test`; they count toward the daily budget but not the run limit.
- **O6 default:** `canManageSettings()` (same as Integrations), no new permission. Needs the owner's confirmation.
- **Unanswered questions** = `out_of_scope` hand-overs of the last 30 days with the customer's last message; **Add FAQ answer** creates a `CompanyFaq` and clears its cache. Knowledge documents come in phase 5.

Files: migration `2026_10_10_000100_create_ai_instruction_versions_table`, `AiInstructionVersion`, `AiInstructionService`, `AgentDesk`, `UnansweredQuestions`, `agent-desk.blade.php`; changes in `AiReplyService`, `AiSettingsService`, `AiRunService`, `SalesReplyRenderer::languageOf()`, `ConversationMessengerService`, `CrmSalesMetrics`, `SalesAutomation`. Tests: `AgentDeskTest`.

### Phase 5 — 2026-10-16 (committed 2026-10-19)

- **Same rule as FAQ answers.** The agent never writes free text, so `lookup_knowledge` returns passages with ids and the agent selects `knowledge:<document id>.<position>`; the server sends the stored passage as written. A passage the agent did not look up in this run is rejected by the renderer. Amounts in a looked-up passage are added to the grounded amounts, so a fee written in a document is not blocked as "ungrounded".
- **Search in PHP, not FULLTEXT** (changed from §4.6): InnoDB FULLTEXT splits Bangla words at vowel signs, so Bangla questions would mostly miss. Passages are cached per company for 5 minutes and the cache is cleared when a document is saved or deleted. Matching: the word, the word without a trailing "s", or (5+ characters) without its last two characters; title/heading hits count three times; at least half of the question's words must match; the best 3 passages go to the agent.
- **Passages:** a heading starts a new topic; text is packed into passages of at most 700 characters; Markdown marks are removed (`**`, backticks, bullets become •, links become `text (url)`).
- **The tool is offered only while the company has an active document**, so companies without documents pay no extra prompt tokens.
- **Admin:** CRM → Knowledge base (create/edit/list, Import file for .md/.txt up to 200 KB, **Test a question**). Permission `crm.manage`. Unanswered questions gets **Add to knowledge base**.
- **Known limit:** hand-over keywords (ফেরত, রিফান্ড, refund, complaint, human, agent) are checked before the FAQ and the knowledge base, so a returns question in Bangla still goes to the team. Needs an owner decision before it is changed.

Files: migration `2026_10_11_000100_create_knowledge_documents_table`, `KnowledgeDocument`, `KnowledgeBaseService`, `KnowledgeDocumentResource` (+ 3 pages); changes in `AiReplyService`, `SalesReplyRenderer`, `UnansweredQuestions`, `lang/*.json`. Tests: `KnowledgeBaseTest` (10).

### Phase 8, checklist part — 2026-10-16 (committed 2026-10-19)

- Built because it needs no owner decision. Phases 6 (O4, O5), 7 (O8, O9) and the go-live switch (O7) still wait for answers.
- A page, **CRM → Setup checklist**, not a dashboard widget: a widget on the main dashboard would show CRM setup to people who never use the CRM. Eight steps, each with a link (shown only when the user may open the target): channel, AI key + switch, business description, products with photos, delivery charges, answers or documents, phone alerts (per user), test chat run.
- Nothing is stored. A step is done when its data exists, so it cannot go stale. Missing: the "go live" step, which needs `ai_live` (O7).

Files: `AiSetupChecklistService`, `AiSetupChecklist` page, `ai-setup-checklist.blade.php`. Tests: `AiSetupChecklistTest` (6).

### Phase 6 — 2026-10-19 (committed 2026-10-19)

- **One decision point for hand-overs:** `AiReplyService::keywordHandover()` (also used by the Agent Desk test chat). Complaint and human words and the discount words go to the team at once; returns/refund words only when no FAQ or knowledge passage covers them; payment, call and booking words always. New reasons and attention kinds: payment proof, asked for a call, asked for a booking, website lead, follow-up due.
- **Website leads** have no chat, so `attention_items` gained `lead_id`; the item closes when the lead leaves status `new`.
- **Follow-ups** (O4): one `crm_sales_follow_ups` row carries the whole schedule (`step`, `base_at`). Offsets = `follow_up_schedule_hours` then `follow_up_weekly_count` weeks of 168 h after the last daily step (hot/warm from §4.7 is dropped — the owner gave one schedule). Quiet hours 22:00–08:00 in the company's time zone push a due reminder to 08:00. Stops on order, opt-out, a person's reply, a newer offer. WhatsApp: approved template every step. Messenger/Instagram: reminder text only inside the 24 h window, otherwise a Needs attention line (`follow_up_due`) and a push alert.
- **Messenger broadcast** (O5): channel `messenger`; audience = the Page's chats with an incoming message in the last 23 h; re-checked per recipient at send time; `Send test` to a chat the sender picks; `tested_hash` ties the test to the Page and the exact text.

Files: migrations `2026_10_19_000100`–`000300`; changes in `AiReplyService`, `AttentionQueueService`, `AttentionItem`, `Conversation`, `Lead`, `LeadController`, `NeedsAttention`, `SalesFollowUpService` (rewritten), `AiSettingsService`, `Integrations`, `SalesFollowUps`, `Broadcast`, `BroadcastRecipient`, `BroadcastService`, the broadcast form and table. Tests: `AiHandoverRulesTest` (11), `SalesFollowUpScheduleTest` (11), `MessengerBroadcastTest` (9); `CrmSalesAutomationTest` and `AttentionQueueTest` adjusted.

### Phase 7 — 2026-10-19 (committed 2026-10-19)

- **Scan parcels** (Courier): input for a USB scanner (Enter) plus camera (`BarcodeDetector`, Code 128 and QR); one `OrderStatusWorkflowService::transition()` per order with source `scan`.
- **Invoice QR dropped:** the invoice already prints a Code 128 barcode of the order number (`Code128::svg`), which both scan paths read.
- **Handover sheet** (Courier): `CourierHandoverSheetService` — bookings of one courier whose `booked_at` is inside the company's day; excludes not booked, pending, cancelled and failed. Fields per O8; no rider block. Print view at `/admin/courier-handover-sheet`.
- **To chase** (CRM): `ToChaseService` over carts, unpaid checkouts (pending `StorefrontPayment` with `checkout_data`) and leads; `chase_marks` stores contacted people. Offer taps are not a source (nothing records them); `StorefrontCheckoutAttempt` is not used (masked contacts only).
- **§4.9 dropped:** no `ai_live`; the setup checklist stays at eight steps. (Reversed on 2026-10-20: see Phase 9.)

Files: migration `2026_10_19_000400_create_chase_marks_table`, `ChaseMark`, `ToChaseService`, `CourierHandoverSheetService`, `ScanParcels`, `CourierHandoverSheet`, `ToChase` (+ 3 views), `courier/handover-sheet.blade.php`, `CourierHandoverSheetController`, route in `routes/web.php`. Tests: `ScanParcelsTest` (6), `CourierHandoverSheetTest` (4), `ToChaseListTest` (7).

### Phase 9 — 2026-10-20 (committed and merged to main 2026-10-20)

Owner instruction: "Meta Seen আর টাইপিং ইন্ডিকেটর … প্রোডাক্ট কার্ড বা carousel … Practice mode সহ কর।" — the last three items.

- **Practice mode is back (reverses O7 of 2026-10-19).** `ai_live`, per company, no entry in `AiSettingsService::DEFAULTS`: `all()` returns the saved value, or `enabled` when nothing was saved, so companies already running stay live. The Integrations form saves it explicitly; for a company that never turned the AI on, its switch starts off. Not live: `maybeReply()` answers only `conversations.is_staff_test` chats (staff mark one from the Inbox header) and writes a `skipped` run for the rest; reminders stay `pending`. The setup checklist has a ninth step, **Go live**, with Go live / Back to practice mode buttons (`canManageSettings()`).
- **Seen / typing:** `ConversationPresenceService` before each model round (setting `typing_indicator_enabled`, default on). Messenger `mark_seen` + `typing_on` (renewed every 12 s) + `typing_off` on a handover; WhatsApp `status: read` + `typing_indicator`. Best effort.
- **Product cards:** `ProductCardService` + `MetaGraphService::sendMessengerCards()` (generic template, up to 10) after the written answer, Messenger only, setting `product_cards_enabled` (default off). The row is stored as an outgoing message with `ai_meta.kind = product_cards`, which `consecutiveAiReplies()` and the AI Inbox Report skip.
- **Not done:** "Seen" when a person opens a Messenger chat in the Inbox; cards in the Agent Desk test chat.

Files: migration `2026_10_20_000100_add_is_staff_test_to_conversations_table`; new `ConversationPresenceService`, `ProductCardService`; changes in `AiSettingsService`, `AiReplyService`, `AiSetupChecklistService`, `AiSetupChecklist`, `AiInboxReportService`, `ConversationMessengerService`, `SalesReplyRenderer`, `SalesFollowUpService`, `MetaGraphService`, `Inbox`, `Integrations`, `Conversation`. Tests: `PracticeModeTest` (12), `AiPresenceAndCardsTest` (13); `AiSetupChecklistTest` expects nine steps.
