# Backend & API Security Audit — 2026-11-01

Standard: OWASP API Security Top 10 (2023), five gates (zero frontend trust, WHO/WHICH/WHAT access control, stateful business flows, zero-trust external APIs, SSRF).
Baseline: `main` @ `288e7fb` (v2.119.0). Branch: `ccr-e2cbd14e-o3wapj`.
Method: read-through of routes, middleware, controllers and services, plus a regression test for every fix. Fixes that could not be proven by a test are not claimed.

## 1. Executive summary

The application was already in good shape against the five gates: every admin download/PDF route checks a permission **and** the company of the record, company isolation is enforced by a global scope plus a contract test, user/role escalation is blocked on the server (not only in the form), all webhooks except the two payment ones verify an HMAC or token in constant time, and the headless storefront prices everything on the server.

Eight problems were confirmed and fixed (V1–V5 on 2026-11-01; V6–V8 in the follow-up pass of 2026-11-02 over the Filament panels, see section 2). The serious one is in the payment flow:

| # | Severity | Gate | Problem | Status |
|---|---|---|---|---|
| V1 | **High** | 3, 4 | Public ZiniPay / PayStation webhook trusted the invoice id in its body: a real paid invoice could be replayed to mark any other pending payment of the same amount as paid and place its order | **Fixed** |
| V2 | Medium | 5 | No SSRF protection on addresses an admin types in (website webhook, WooCommerce, payment and SMS gateway) or that a remote service returns (images, video links) | **Fixed** (see risks R1 for what is deliberately left) |
| V3 | Medium | 1 | CSV exports wrote public-supplied text (customer name/address from the checkout) as it was: formula injection in Excel/Sheets | **Fixed** |
| V4 | Low | 2C | Installer accepted an 8-character password for the first Super Admin (everywhere else needs 12 + mixed case + number + symbol) | **Fixed** |
| V5 | Low | 4 | WooCommerce webhook mismatch log wrote the full computed signature (valid for the body just sent) | **Fixed** |
| V6 | Medium | 2A | CRM **leads, quotations, quick replies and FAQs** (and a lead's follow-up activities) were open — view, create, edit, delete — to every staff role, including an accountant or inventory staff with no CRM permission. Customer names/phones, quotes, and the FAQ text the AI replies from were readable and editable | **Fixed** |
| V7 | Medium | 2B | **A voucher could be opened by id by any staff member.** The "own vouchers only" rule (`voucher.view`, versus `voucher.view_all`) was applied to the list's table query only; the single-voucher page does not use that query, and ids are sequential | **Fixed** |
| V8 | Low–Medium | 2A | **Shipments and containers** (hidden from the menu, but reachable by URL) and the **exchange-rate list** had no access rule for viewing; shipments and containers could also be created, edited and deleted by anyone | **Fixed** |

## 2. What was reviewed

- Framework: Laravel 12, Filament 5 (5 panels: admin, reseller portal, investor portal, IMS office, trade portal), Livewire, per-company hashed Bearer API keys, Blade storefront, headless Next.js API.
- 659 registered routes. Read in full: the 6 website API routes, the 29 headless storefront routes, the 10 webhook routes, every plain `auth` web route in `routes/web.php` (≈40: PDFs, CSV, downloads, backups, push devices, company switch, app upgrade), the public storefront/preview/quotation/chat-order/TLS-ask/install/health routes, all API/portal middleware, and the payment, checkout, account, tracking, OTP and webhook services behind them.
- **Follow-up pass, 2026-11-02 — Filament panels (the former R10).** Findings V6–V8 come from it. How it was done: (1) read how authorization really works here: there are **no model policies**; `Gate::before` decides, and only for models listed in `User::MODEL_MODULES`; for any other model it returns null, which Filament (no policy) reads as **allowed** (`vendor/filament/filament/src/helpers.php`, `get_authorization_response()`); (2) a reflection scan of all 92 Filament resources and 112 pages for the ones whose model is not in `MODEL_MODULES` and that define no access rule of their own — 9 resources and 1 page (the reseller dashboard, which sits behind the reseller guard) came out; (3) each of the 9 was read, then **probed over HTTP** as an accountant and as inventory staff (neither has `crm.view`), 403 on the control page and 200 on the CRM resources, which is how V6/V8 were proved; (4) a search for the same shape of flaw as V7 — a per-user filter applied to a table but not to the single-record page — found only the vouchers (the AI image/video pages filter by `user_id` where they act on an id); (5) every `{!! !!}` in the views, every raw SQL fragment and every `Process` call was read: all escaped, constant, or array-argument.
- **Still sampled, not audited line by line:** the Livewire actions *inside* resources whose access rule is a plain permission check (they inherit the resource's rule; custom table actions that skip it were not each opened), and the office / investor / trade / reseller portals beyond what is listed above and in the first pass (user/role management, `User::canManageUsers` / `canChangePermissions` / visibility rules, the reseller portal's Livewire pages where record ids are re-scoped to the signed-in reseller, the panel `getEloquentQuery` scoping and the isolation tests).
- Not reviewed: Android wrapper, `whatsapp-bridge`, n8n workflows, Next.js front end (separate repo).

## 3. Findings by gate

### Gate 1 — Zero frontend trust: pass
- Every admin web route is behind `auth` and each controller re-checks a permission (`canPerformModelAbility`, `hasPermission`, `canExportReports`, `canManageBackups`) and, for stored files, the record's company and `canAccessCompany` (`ConversationMediaController`, `VoucherAttachmentDownloadController`, `ExpenseScanImageController`, `Investment*DownloadController`, `InvestorContractDownloadController`).
- Public shareable documents use signed URLs (`quotation.public`, `vouchers.receipt`).
- Store preview routes (`/storefront/{company:slug}`) are `404` for the public in production and, for staff, limited to their own companies.
- `AppUpgradeController::safeReturnUrl` only accepts same-host `/admin…` return targets (no open redirect). The checkout API accepts `return_url` / `cancel_url` only on the verified Next.js domain over https.

### Gate 2 — WHO / WHICH / WHAT: pass, with notes
- **WHO (BFLA):** user management is enforced server-side: `CreateUser` / `EditUser` drop a submitted `role` unless the actor is a Super Admin, `UserRoleResource` is Super-Admin only, a user only sees users of their own companies (`User::canSeeUser` / `scopeVisibleTo`), only a Super Admin may change, delete or reset the password of a Super Admin (`User::canModifyUser`), and the last active Super Admin cannot be removed.
- **WHICH (BOLA):** storefront account endpoints always query through `ordersQuery($customer)` (customer id + source), tracking needs order number + the order's phone (with a per-order wrong-guess limiter) or the signed order token, payment finalize needs an HMAC `ref`, reseller portal records read in the pages checked (`Products`, `EditOrder`) are filtered by the signed-in reseller, tenant isolation relies on `BelongsToCompany` + `CompanyScope` with `SetCurrentCompany` pinned before `SubstituteBindings`. A bearer API key resolves exactly one company and clears the context afterwards.
- **WHAT (BOPLA / mass assignment):** every model declares `$fillable` (none uses `$guarded = []`); API endpoints validate with explicit rule lists and write only allow-listed fields (`ProductController::update` takes `price`, `sale_price`, `stock` and routes stock through the ledger).
- **V6 / V8 (found 2026-11-02).** A resource whose model is not in `User::MODEL_MODULES` and that defines no `canAccess()` / `canViewAny()` of its own is open to every staff member of the panel, whatever the role — proved with an accountant and an inventory-staff user, both without `crm.view`: `/admin/crm/leads`, `/quotations`, `/quick-replies`, `/company-faqs` (and their `create` pages), `/admin/shipments`, `/admin/containers` all answered 200, while `/admin/crm/inbox` (which has a rule) answered 403. Fix: new concern `App\Filament\Concerns\AuthorizesByPermission` overrides `getAuthorizationResponse()` — the single method every Filament ability and page route ends in — and maps view / create / update / delete to named permissions (leads, quotations, quick replies, FAQs: `crm.view` to see, `crm.manage` to change, the same pair the Inbox and the broadcast/knowledge resources already use; shipments and containers: `purchasing.view / create / update / delete`, the Purchases module). An unknown ability is refused. The lead's follow-up-activities relation manager follows the lead's rule (`LeadResource::permits()`), so a view-only role cannot add, edit or delete an activity. `ExchangeRateResource::canViewAny()` now needs `investments.view` (or `.manage`), like the rest of its cluster.
- **V7 (found 2026-11-02).** `VoucherResource::table()` shows a staff member only their own vouchers unless they hold `voucher.view_all`, but `/admin/finance/vouchers/{id}` resolves the record through the resource's `getEloquentQuery()` (no filter) and `canView()` was not defined, so a colleague with only `voucher.view` opened another person's voucher page (the response was 200; the page shows the voucher's details) by counting ids. Fix: `VoucherResource::canView()` allows the submitter or `voucher.view_all`; `canViewAny()` now needs some voucher permission (a role with none could open the page before). Attachment downloads were already checked this way (`VoucherAttachmentDownloadController`).
- Notes: see R3 (API order `unit_price`), R5 (push device re-assignment), R11 (media library), R12 (generation prefill).

### Gate 3 — Stateful business flows: one real flaw (V1), rest pass
- **V1** — `StorefrontPaymentService::verifyAndFinalize()` used the invoice id from the request (`ZiniPayWebhookController.php:16`, `PayStationWebhookController.php:23`) instead of the one stored on the payment, then compared only the *amount*. `webhooks/zinipay/{payment}` and `webhooks/paystation/{payment}` are public and payment ids are sequential, so: pay one real invoice, then POST `{"invoice_id": "<that invoice>"}` to the webhook of every other pending payment of the same amount (a fixed new-customer delivery advance makes equal amounts common) — each is marked completed and `placePaidCheckout()` creates its order without a payment.
  Fix: the stored invoice is the only one verified (a different id in the body is logged and ignored; a body id is used only when nothing is stored); completion runs in a transaction under a row lock and refuses an `invoice_id` or `transaction_id` that already settled another payment of the same gateway; two simultaneous callbacks cannot both complete a payment.
- Checkout is idempotent per `client_request_id` (cache lock), `placePaidCheckout()` is idempotent under `lockForUpdate`, prices are computed server-side (`StorefrontCatalogService::unitPrice`), `expected_total` only detects a changed cart.

### Gate 4 — Zero-trust external APIs: pass after V1/V5
- WooCommerce: HMAC-SHA256 over the raw body, `hash_equals`, secret per company; missing secret → refused. Couriers: constant-time bearer token + optional `X-Signature` HMAC, dedupe on delivery id, rejected calls logged. Meta and WhatsApp-Web bridge: `sha256=` HMAC over the raw bytes with a per-channel app secret, **fail closed when no secret is saved**. Amazon SES/SNS: full SNS signature verification (certificate host pinned to `sns.<region>.amazonaws.com` over https, topic ARN match, per-site URL token).
- Payment gateways: status and amount are re-fetched from the gateway server-to-server and never read from the callback (V1 closed the remaining hole).
- No third-party JWTs are consumed.
- Not available from these providers: a signed timestamp. Replay is handled by idempotency (courier `delivery_id`, WooCommerce `external_reference`, payment row lock) rather than a time window — see R4.

### Gate 5 — SSRF: V2
Before: only `MetaGraphService` blocked private IPs. The website webhook URL, the WooCommerce site URL (also used to download product images named in the remote store's data), the ZiniPay/PayStation base URLs, the SMS gateway URL template and the video link returned by a custom video service were all called as typed, with redirects followed. An admin with Integrations access (or a hostile/compromised WooCommerce store, for image links) could reach `169.254.169.254` or any internal service; the SMS path also reflected the HTTP status back.
Fix: `OutboundUrlGuard` — https only (plain http only for SMS gateways and video links), no credentials in the URL, no `localhost` / single-label / `.local` / `.internal` names, numeric forms such as `2130706433` and `0x7f.1` refused, DNS looked up and **every** answer must be public (IPv4-mapped IPv6 and NAT64 judged by their IPv4 part; 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, 100.64/10, documentation and benchmark ranges, ::1, fc00::/7, fe80::/10 refused), the connection is pinned to the checked IP (`CURLOPT_RESOLVE`, defeats DNS rebinding), and every redirect target is re-checked (3 redirects at most). The Integrations form uses the same check as a validation rule when an address is saved (the rule class is tested; the form itself was not exercised in a browser). Tests cover each class above.

## 4. Vulnerabilities fixed — files

| # | Files |
|---|---|
| V1 | `app/Services/StorefrontPaymentService.php` |
| V2 | `app/Support/OutboundUrlGuard.php`, `app/Support/UnsafeOutboundUrl.php`, `app/Rules/SafeOutboundUrl.php`, `config/outbound.php`, `app/Jobs/DispatchWebsiteWebhookJob.php`, `app/Services/{WooCommerceImportService,WooCommerceOrderSyncService,WooCommerceDescriptionRepairService,ZiniPayClient,PayStationClient,StorefrontNotificationService}.php`, `app/Services/VideoGeneration/Providers/CustomVideoProvider.php`, `app/Filament/Pages/Integrations.php`, `lang/en.json`, `lang/bn.json` |
| V3 | `app/Support/CsvSafe.php`, `app/Services/{CustomerCsvService,SupplierCsvService,ProductCsvService,ReportExportService}.php`, `app/Filament/Pages/ConversationActivity.php` |
| V4 | `app/Http/Controllers/InstallController.php` |
| V5 | `app/Http/Controllers/WooCommerceWebhookController.php` |
| V6 | `app/Filament/Concerns/AuthorizesByPermission.php` (new), `app/Filament/Resources/{Leads/LeadResource,Quotations/QuotationResource,QuickReplies/QuickReplyResource,CompanyFaqs/CompanyFaqResource}.php`, `app/Filament/Resources/Leads/RelationManagers/ActivitiesRelationManager.php` |
| V7 | `app/Filament/Resources/Vouchers/VoucherResource.php` (`canViewAny()`, `canView()`) |
| V8 | `app/Filament/Resources/{Shipments/ShipmentResource,Containers/ContainerResource}.php` (the concern), `app/Filament/Resources/ExchangeRates/ExchangeRateResource.php` (`canViewAny()`) |

## 5. Remaining risks (not fixed — decide)

| # | Risk | Severity | Why not fixed / what to do |
|---|---|---|---|
| R1 | Not covered by the SSRF guard: AI model base URLs (Integrations → AI has an **Ollama on `localhost`** preset), the WhatsApp bridge, Coolify, and a custom video provider's own submit/poll address (a **self-hosted** service, often on the LAN) | Medium | Blocking them would break features that are meant to reach private hosts. Add the hosts you really use to `OUTBOUND_ALLOWED_HOSTS` and extend the guard to AI URLs later, or restrict who may open Integrations → AI. |
| R2 | Storefront sign-up with **no SMS gateway** lets a visitor register a phone number that has no customer record yet; when that person later orders, the order attaches to the squatter's account. (With a gateway, the phone is proven by an SMS code.) Already recorded as an open decision in `UPDATE_NOTES.md` | Medium | Needs an owner decision: require SMS (or e-mail) proof for every sign-up, or only attach orders to an account whose phone was verified. |
| R3 | `POST /api/v1/orders` lets the API-key holder set `items.*.unit_price`, and overwrites name/address of an existing customer with the same phone | Low–Medium | By design for the partner website (it sells at its own prices); the key is the trust boundary. Consider a per-key "may set prices" flag and not overwriting an existing customer's address. |
| R4 | No signed timestamp on WooCommerce/courier/payment callbacks (providers do not send one); replay is limited by idempotency. A replayed older WooCommerce `order.updated` could re-apply an older status — not verified in this audit | Low | Compare the payload's `date_modified` with the stored sync time. |
| R5 | `PushDeviceController::store` re-assigns an existing device row to the signed-in user when the device id (or token) matches | Low | Needs the victim's random device id; only costs the victim a push registration. |
| R6 | Order tracking locks an order number for 15 minutes after 8 wrong phone guesses, so a stranger can briefly lock a customer out of tracking | Low | Acceptable trade-off; key the limiter by IP as well if abused. |
| R7 | A courier provider can be set to `webhook_signature_required = false`, which accepts unsigned calls | Low | Admin opt-out; consider removing it or showing a red warning. |
| R8 | Order token / payment `ref` are HMACs of the record id with `APP_KEY` and never expire | Low | Rotating `APP_KEY` invalidates them (it invalidates sessions and encrypted data too, so plan it). |
| R9 | `/health/version` and `/webhooks/mobile-crash-reports` are public by design (commit id / deployment id; unauthenticated crash upload, 20/min/IP, 20 KB) | Low | Trim the version payload to what the deploy check needs. |
| R10 | The Filament panels were audited at the resource level in the 2026-11-02 pass (every resource and page checked for an access rule, V6–V8 fixed), but the Livewire actions *inside* resources that have a plain permission check were not opened one by one | Low–Unknown | Spot-check custom table/header actions that call a service directly. `PanelResourceAuthorizationTest::test_every_filament_resource_has_access_rules` now fails the build when a new resource has no access rule, so this class of flaw cannot return unnoticed. |
| R11 | **Media library** (`MediaResource`, Settings → Media): any staff member of the company may upload to it and delete from it, whatever their role. Deleting a picture the storefront or a product uses breaks it | Medium | Needs an owner decision on which permission it should need (inventory? marketing? settings?). It is the only entry in the test's known-open list; the test fails once a rule is added, so the entry gets removed. |
| R12 | The image and video generation tools pre-fill the form from an earlier generation by id (`?from=`) without checking that the generation is the signed-in user's; the actions on a generation (view, publish, delete) do check `user_id` | Low | Only the form settings (prompt, provider) of a colleague in the same company can be read, not their result files. Restrict it to the user's own generations, or to the tool's reviewers, if prompts are sensitive. |

## 6. Security tests added

| Test file | Proves |
|---|---|
| `tests/Feature/PaymentWebhookReplayTest.php` (5) | a paid invoice named in the body cannot settle another payment; the stored invoice still completes the payment; one gateway transaction settles one payment; a payment with no stored invoice accepts a fresh invoice once and refuses a reused one; an amount mismatch never completes |
| `tests/Feature/OutboundUrlGuardTest.php` (39) | 28 unsafe address forms refused; public address allowed and pinned; DNS answers (private, mixed, IPv6) refused; unresolvable name refused in production only; redirect to the metadata address stopped; env/config exceptions; save-time rule; webhook, ZiniPay base URL and SMS template never send a request to an internal address |
| `tests/Feature/VideoGenerationProviderAdaptersTest.php` (+1) | a video link pointing at the metadata service is never downloaded |
| `tests/Feature/CsvFormulaInjectionTest.php` (4) | formulas are neutralised, numbers/phones untouched, customer and product exports checked end to end |
| `tests/Feature/InstallerPasswordPolicyTest.php` (2) | weak first-admin password refused, strong accepted |
| `tests/Feature/PanelResourceAuthorizationTest.php` (23) | accountant and inventory staff get 403 on all eight CRM pages (list and create) while sales staff, manager and super admin keep 200; a custom view-only CRM role can read but not create; the lead-activities relation manager follows the lead rule; an unknown ability is refused; shipments and containers follow `purchasing.*`; a voucher opens only for its submitter or `voucher.view_all`, never for a colleague or a role without voucher permission; the exchange-rate list needs `investments.view`; **every Filament resource must either list its model in `MODEL_MODULES` or define an access rule** (the guard for the future, with `MediaResource` as the one documented exception, R11) |

V1 and V3 tests were run against the old code first: 3 of 5 and 2 of 4 failed there, all pass with the fixes. For V6–V8 the CRM / shipment / container pages were probed on the old code (200 for roles without the permission), and the voucher and exchange-rate assertions failed there before the fix.

## 7. Recommendations for production

1. **Check for past abuse of V1.** Completed payments sharing an invoice or transaction should not exist:
   ```sql
   SELECT gateway, invoice_id, COUNT(*) c FROM storefront_payments WHERE status='completed' AND invoice_id IS NOT NULL GROUP BY gateway, invoice_id HAVING c > 1;
   SELECT gateway, transaction_id, COUNT(*) c FROM storefront_payments WHERE status='completed' AND transaction_id IS NOT NULL GROUP BY gateway, transaction_id HAVING c > 1;
   ```
   Any row is a payment to review against the gateway's own statement.
2. Add a network-level egress rule on the app/queue containers denying `169.254.169.254` and the private ranges (the guard is application-level; this is the second layer). On AWS, require IMDSv2.
3. Leave `OUTBOUND_ALLOW_PRIVATE_HOSTS` unset in production; list only the exact hosts you need in `OUTBOUND_ALLOWED_HOSTS`. Open **Integrations** once after deploy: saved addresses that are not public will now fail with a clear message at use time.
4. Decide R2 (sign-up proof) and R1 (AI/self-hosted hosts) before the Next.js cutover.
5. Take a backup and deploy to staging first; run `php artisan test` there.
6. **Review the custom roles (Users → Roles) after deploy (V6–V8).** The built-in roles keep what they need (Manager and Sales Staff have `crm.view` + `crm.manage`; Accountant, Inventory Staff and Manager have `purchasing.view`). A **custom** role that used the CRM leads, quotations, quick replies or FAQs without `crm.view` / `crm.manage`, or shipments / containers without `purchasing.*`, or the exchange-rate list without `investments.view`, only worked because those pages were open; it now gets a 403 until the owner adds the permission. A person who needs a colleague's voucher needs `voucher.view_all`.
