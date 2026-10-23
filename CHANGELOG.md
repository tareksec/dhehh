# Changelog

All notable production changes to Business Dashboard are documented here.

## [Unreleased]

## [2.111.2] - 2026-10-23

**Release type:** Patch/Fix Update

### Fixed

- IMS office top bar: a wide logo no longer makes the bar tall (logo capped at 2rem high, 8rem wide; the wrapped two-line bar on laptops is shorter).
- IMS office top bar on laptops (1024–1799px): one slim line instead of two — the company picker is a round icon, the language switch shows only the other language, the menu pills are tighter; only below 1240px does the menu still get its own line.
- IMS office top bar: choosing another company now shows that company's logo and name instead of the IMS domain's.

### Technical Notes

- `ImsOfficePanelProvider::officeCompany()` prefers the open company (`CompanyContext`) over the IMS host company; CSS in `resources/css/filament/ims/theme.css`.
## [2.111.1] - 2026-10-23

**Release type:** Patch/Fix Update

### Fixed

- **Trade Portal: a new LC file no longer blanks the supplier's saved address and contacts.** Found while checking what the owner writes only once. The new-LC form did not show the supplier's saved company name, country, address, phone and e-mail, and saving it with those boxes empty wrote the empty values back to the supplier, so the second LC for the same supplier wiped what the first one had saved. Choosing the purchase now fills those boxes from the supplier.

### Technical Notes

- `LcFileResource` form: `purchase_id` is `live()` and `afterStateUpdated` sets the `LcFileService::SUPPLIER_FIELDS` from the purchase's supplier. Test: `TradePortalQaRegressionTest::test_a_second_lc_form_brings_the_suppliers_saved_details_and_keeps_them`.

**Release type:** Patch/Fix Update

### Fixed

- **Trade Portal fixes from the live check of 2026-10-23** (Tasneem Knitting Industry, test data only).
  - **A Trade Portal page that breaks now shows "This page could not be opened" with a way back, and the error reaches the Super Admins (Settings → Deploy Error Logs).** Before, a crash on `/trade` showed a bare "500" page and was written nowhere the owner could see, so the server errors found in the check (the first save of an LC file's details, the DRL before its date was filled, the first opening of Serial numbers, the *Landed cost per shipment* report, the PDF of the *Shipment advice and insurance register*) could not be traced. The same pages and the same data were run on a copy of the system (SQLite and MySQL/MariaDB) and all of them open, save and print there; the next time one fails on the live site, the Deploy Error Logs will say why.
  - **Goods in has its New purchase button again.** It was hidden from a Trade Owner by the ERP's purchase rules (the page itself opened by its address).
  - **Landed cost → Apply to stock buying price** now lists the new buying price of each item in its confirmation (for example "Machine ৳ 45,189.19; Stand ৳ 2,259.46; Feeder ৳ 564.86"), which is what is applied. Before, it showed one blended cost per unit that no item received.
  - **Home's LC card counts an LC until its LC file is Cleared**, as the reports do, also when the goods are already in. Before, an LC whose goods had arrived dropped off the card even though it was still open at the bank.
  - **The English report PDFs write "Tk" for Taka**: the PDF engine drew the ৳ sign like a "b". The screen and Print pages keep ৳.

### Technical Notes

- `PanelErrorPages::PANELS` gains `'trade' => '/trade'`, so Trade Portal page crashes and failed Livewire updates get `panel-errors.module` and `ModuleGuard::report()` (→ `DeploymentErrorReporter`) instead of the storefront's 500 page.
- `GoodsInResource::getCreateAuthorizationResponse()` returns `canCreate()`: Filament's `CreateAction` asks the Gate, not `canCreate()`, and `Gate::before` (AppServiceProvider) decides `Purchase` by the ERP's module permissions (`User::MODEL_MODULES`), which a Trade Owner does not have.
- `TradePortalHome::openLcs()`: with an LC file, open = stage ≠ `cleared`; without one, the old rule (purchase not received). Cancelled purchases never count.
- `TradeReportFormat::pdf()` (= `text()` with `Tk` for `৳`) is used by `trade/reports/pdf.blade.php`.
- Translation key replaced (en + bn): the Apply confirmation now takes `:items` instead of `:per_unit`.
- Not changed: two serial numbers written on one line are one serial number. The form splits on new lines, commas and semicolons; the check's "TEST-SN-001TEST-SN-002" was typed without a line break.
- Tests: new `TradePortalQaRegressionTest` (the check's data: every report on screen in both languages and as PDF / Excel / Print, the DRL without a date, LC edit / landed cost / serial actions, Home LC card, Goods in button, the Trade module error page); `ModuleGuardTest` checks the `/trade` mapping.
## [2.111.0] - 2026-10-23

**Release type:** Minor Feature Update

### Added

- **Settings → Amazon SES: control the Amazon SES account from the dashboard.** Owner request 2026-10-23. A new page, with one AWS key per site (a separate AWS user for the dashboard, not the SMTP login; the secret is stored encrypted and never sent back to the browser):
  - **Setup checklist** (the AWS key works, out of the sandbox, the From address is on a verified domain, the site sends through SES, tracking is on), each failing step saying what to do.
  - **Account:** sandbox or production, sending on or paused, sent in the last 24 hours against the daily limit, the send rate, and Amazon's own health rating for the account.
  - **Domains and addresses:** list with verification state; **add** a domain (the three DNS records to publish are shown right under it) or an address (Amazon emails it a link); **remove** one (a warning when it is what the site sends from).
  - **Addresses Amazon will not send to** (its suppression list after a bounce or complaint): **allow again** or **block** an address.
  - **Bounce, complaint and delivery tracking:** one button creates what is needed in the AWS account (an SNS topic, an SES configuration set and a subscription to this ERP) and one removes it again. Needs the site to be on a public https address.
  - **Several companies on one AWS account:** each company connects separately (its own site's From address, its own copy of the AWS key, its own tracking topic and configuration set, so a report always lands in the right company's Email Log). The domain list shows the company's own domains first and the other companies' domains below, read-only; only a super admin can remove a domain that is not the company's own. The account page says the limits and the health rating are shared. Step-by-step: `docs/email-ses-setup.md` section 5.
  - The page lists the AWS permissions the key needs as a policy to paste into IAM. A missing permission is explained in words and only hides its own part of the page.
- **The Email Log now follows what happens after the mail server accepts a message.** With tracking on, a **Sent** row becomes **Delivered**, **Bounced** (hard, soft or suppressed, with the recipient server's own answer) or **Marked as spam**, each with a plain reason. A worse result is never replaced by a better one (a bounce stays a bounce). The log filter has the new results.

### Changed

- The Email Log note no longer says bounces are never tracked: it explains what **Sent** means and points to Settings → Amazon SES.
- A "configuration set does not exist" refusal from Amazon (the tracking set was deleted in AWS) is explained in the Email Log: turn tracking off and on again.

### Security

- The webhook Amazon calls is protected three ways: the address carries a token derived from the app key, every message must carry a valid Amazon SNS signature (the certificate is only fetched over https from an `sns.<region>.amazonaws.com` address), and the message must name the topic that was created for that site. A forged or misdirected message writes nothing. The subscription confirmation is only followed to an SNS address.

### Technical Notes

- New services under `App\Services\Ses`: `AwsClientFactory` (the one place AWS SDK clients are built; tests bind a mock-handler subclass, so no test calls AWS), `SesApiService` (account, identities and their DKIM records, suppression list; failures become `SesApiException` with an actionable message), `SesTrackingService` (turns tracking on and off: STS caller identity, SNS topic with a policy that lets this account's SES publish, SES configuration set `zamzam-erp-<company>-<site>` with an SNS event destination for DELIVERY / BOUNCE / COMPLAINT / REJECT, https subscription), `SesEventService` (applies an SES event to the Email Log row by message ID, else by recipient among the last 7 days' unanswered sends, else as a new row), `SnsMessageValidator` (SNS signature versions 1 and 2).
- `SesWebhookController` (`POST /webhooks/ses/{setting}/{token}`, route `webhooks.ses`, CSRF-exempt, throttled); the token is `hash_hmac(app key, "ses-events|<setting id>")`, so nothing is stored for it.
- `StorefrontNotificationService`: mail through an SES SMTP host names the configuration set in the `X-SES-CONFIGURATION-SET` header while tracking is on (per site, no change to the SES identities), and the SES message ID is read from the SMTP answer (`250 Ok <id>`) and kept on the log row. New `SES_CREDENTIAL_KEYS` in the encrypted `notification_credentials` (`ses_api_key_id`, `ses_api_secret`, `ses_api_region`, `ses_config_set`, `ses_topic_arn`, `ses_tracking_enabled`, `ses_subscription_confirmed_at`); `EditStorefrontSetting` carries them over when Storefront Settings is saved.
- Migration `2026_10_23_210000_add_message_id_to_email_logs_table.php` (`message_id`, named index). `EmailLog` gains the statuses `delivered`, `bounced`, `complained`.
- New page `App\Filament\Pages\AmazonSes` (Settings cluster, `canManageSettings()`, never fills the secret back into the form) and its view; `docs/email-ses-setup.md` and `docs/codebase-map/crm-messaging.md` updated.
- Tests: `SesApiServiceTest` (8), `SesTrackingTest` (18, including SNS messages signed with a certificate generated in the test), `AmazonSesPageTest` (13), new cases in `EmailLogTest`; `tests/Feature/Concerns/FakesAws.php` is the shared Amazon stand-in. Checked in a real browser (desktop and 390 px, no sideways scroll, the add-domain dialog opens) with Amazon faked.
## [2.110.0] - 2026-10-23

**Release type:** Minor Feature Update

### Added

- **Dark / night mode button in the header of the reseller landing page.** Owner request 2026-10-23: a moon icon (a sun while the page is dark) next to Sign in and Apply. One press switches the whole page between the light and the dark look, and the choice is remembered on that device (`localStorage`, key `resellerLandingTheme`) and applied before the page paints, so there is no flash. Until the visitor presses it the page follows the device's own light/dark setting, as before. The button's label and tooltip read "Switch to dark mode" / "Switch to light mode" (Bangla: ডার্ক মোডে যান / লাইট মোডে যান) and its pressed state is announced to screen readers.

### Changed

- **The landing page header shows the Next.js website's logo, one for each background.** Owner request 2026-10-23: the header used the company's own logo from Company Settings, not the one the website shows. It now draws the logo of the company's Next.js site (Site → Storefront Settings → the Next.js row) exactly as the website's header does: the white-background logo in light mode and the black-background logo (the website's "dark mode" logo) in dark mode, swapped when the visitor presses the new toggle. A site with only one logo shows that one in both modes, and the company's own logos are the fallback when the website row has no logo (or the company has no Next.js row), so a store without a website is unchanged.
- **On a phone the header's Apply button reads "Apply" (Bangla: আবেদন করুন) instead of "Apply now", and the language switch and the "Reseller guide" tag appear from tablet width (768 px) instead of 640 px.** With the new toggle, the logo, Sign in and the long Bangla "এখনই আবেদন করুন" did not fit side by side on a 360-390 px phone, so the logo was squeezed out. The language switch is still in the page's first block on a phone, and the full "Apply now" button is the first button of the page.

### Technical Notes

- Reseller landing page header: `StorefrontSiteConfigService::branding()` now holds the light/dark logo pair that `/site` (`branding`) already returned, and `ResellerGuideController` reads it for the page (`logos()`: Next.js row pair, else the company's `logoUrl()` / `darkLogoUrl(false)`; one `website()` lookup now feeds both the logos and the footer, each inside its own `ModuleGuard`). `guide.blade.php` draws `nb-logo--light` + `nb-logo--dark` only when a dark logo exists and swaps them with `.dark .nb-logo--*` rules in `reseller-landing.css`; the toggle (`data-theme-toggle`, `nb-btn--icon`) swaps the moon/sun icons with CSS and keeps its label in a small inline script. No new translation keys ("Apply", "Switch to dark mode" and "Switch to light mode" already exist). Tests: `ResellerLandingPageTest` (logo pair, single logo, company-logo fallback, toggle and saved choice).
## [2.109.0] - 2026-10-23

**Release type:** Minor Feature Update

### Added

- **The SMS gateway address is built from the API key and sender ID boxes.** Owner request 2026-10-23: on Settings → Integrations → SMS Gateway you type the API key and the sender ID and the address follows, the way {phone} and {message} are filled in when a message is sent, so nobody edits the address by hand. Typing the key or the sender ID while the address box is empty puts the BulkSMSBD address in, a new line **The address the system will call** shows it live (the API key only in part), and the sparkle button next to the address box puts the BulkSMSBD address back. An address pasted from BulkSMSBD's example, with a real key or sender ID or the sample words (`api_key=…`, `number=Receiver`, `message=TestSMS`) in it, is turned back into placeholders as soon as the box is left or the page is saved, and a key or sender ID found in it moves to its own box when that box is empty. Another gateway's address is never touched.
- **SMS Dashboard (Settings → SMS Dashboard).** Owner request 2026-10-23: see how many SMS went, how many failed, what it cost and what is left on the BulkSMSBD account without logging in to BulkSMSBD. It shows, for today / the last 7 days / the last 30 days / this month: **SMS sent**, the SMS **parts** used (a Bangla or long message takes more than one), **refused by the gateway** (with a link to the SMS Log for the reason), **not sent by our own rules** (no wording, opted out; these cost nothing), the messages still waiting in the queue, a split by kind of message, and a day-by-day bar list. The **balance on BulkSMSBD** is read live from BulkSMSBD's balance API (refresh button, kept for five minutes), with a red warning when it falls below the amount you set, and a **Recharge on BulkSMSBD** button that opens their site. **Limits, set by BulkSMSBD and not by us:** its API has no recharge, no delivery report and no price list, so a recharge is still made on their site, "sent" means the gateway accepted the message (not that the phone received it), and the cost is an estimate (parts × your price per SMS), not BulkSMSBD's own charge.
- **Price per SMS** and **Warn me when the balance is below** on Settings → Integrations → SMS Gateway. The first is your own price (the cost is not shown until you enter it); the second drives the low-balance warning.
- **Every SMS is now counted, not only order SMS.** Login codes, recovery codes, abandoned-cart and lead reminders, broadcasts and investor/IMS messages were not logged at all. They now leave a row in the SMS Log of kind "Other messages" with the phone number, the result, the gateway's answer and the parts used. The text is **never** stored, so a login code does not sit in the database.

### Fixed

- **The test SMS said "1001 Invalid Number" for a correct phone number.** Owner report 2026-10-23 (the API key was already accepted by then). The "What was sent" line in the failure notice showed the real cause: the saved gateway URL still had the words from BulkSMSBD's example (`number=Receiver`, `message=TestSMS`) where `{phone}` and `{message}` belong, so every SMS, the test and all order SMS, went to a number called "Receiver". The gateway URL box now refuses to save a URL without `{phone}` and `{message}` and shows the correct URL as an example; a URL like that already saved is not called at all, the SMS Log says "The SMS gateway URL has no {phone} or no {message} in it, so nothing was sent", and the SMS Dashboard shows a red warning with a link to fix it. Also: the system always sends the number as `8801XXXXXXXXX` and does not repeat an `88` or `880` written in front of `{phone}`, and when the gateway refuses an SMS, **Send test SMS** shows **what was sent** (the gateway address with the number and sender ID, the API key and the text hidden).
- **A connection error could put the SMS API key on screen and in the SMS Log.** When the gateway could not be reached, the request's error message (which names the whole gateway URL, API key included) was saved in the SMS Log's detail column and the application log. Gateway URLs are now removed from those messages before they are saved or shown, and the same is done to rows an earlier version already wrote. If a gateway connection error ever appeared in your SMS Log, consider replacing the BulkSMSBD API key (Developers page) as a precaution.

### Technical Notes

- `StorefrontNotificationService`: `smsRequestUrl()` builds the gateway address (one place for the send and the preview, with the `88{phone}` / `880{phone}` rule), `smsRequestPreview()` hides the key (also when it is typed into the URL) and the text; `sendSmsDetailed()` returns it as `request`. Tests: `SmsDashboardTest` (+2).
- `App\Support\SmsGatewayUrl`: `BULKSMSBD` (the placeholder address), `tidy()` (BulkSMSBD hosts only; parameter names `api_key`, `senderid`, `number`, `message`; a filled box wins over a value found in the address; sample words are dropped, never taken for a value), `preview()` (key shortened). `Integrations` calls it from `tidyGatewayUrl()` (URL box `afterStateUpdated`, the sparkle suffix action and the start of `save()`, before validation) and `fillEmptyGatewayUrl()` (API key / sender ID `afterStateUpdated`, only when the address is empty). Covered by `SmsGatewayUrlTest`.
- `StorefrontNotificationService::smsTemplateMissing()` lists which of `{phone}` / `{message}` a URL template lacks; it feeds the save rule on `Integrations` (SMS gateway URL), the `bad_gateway_url` send reason in `deliverSms()` (no gateway call, reason label in `SmsLogService::reasonLabels()`, `detail` = "missing {phone}, {message}") and the red warning on the SMS Dashboard (`urlMissing`). A template saved before this check is not changed, only refused at send time.
- SMS dashboard: `App\Filament\Pages\SmsDashboard` (Settings cluster, "manage settings" permission), `SmsUsageService` (aggregates the SMS log; the company scope applies), `SmsBalanceService` (BulkSMSBD hosts only, host taken from the saved URL template, answer parsed from a `balance` field or a bare number, anything else shown as it came; the real reply for a valid key could not be checked here), `App\Support\SmsSegments`. `sms_logs.segments` (migration `2026_10_23_210000`, backfills older rows from their text and scrubs gateway URLs from old `detail` values); `SmsLog::KIND_OTHER`; `StorefrontNotificationService::sendSms()` now writes the `other` row through `SmsLogService::other()` and `sendSmsDetailed()` also returns `segments`; `scrubUrls()`. `sms_price_per_sms` and `sms_low_balance_alert` join `SMS_CREDENTIAL_KEYS` (kept by both Integrations and Storefront Settings). Tests: new `SmsDashboardTest` (13). Lang: 41 new keys.
## [2.108.0] - 2026-10-23

**Release type:** Minor Feature Update

### Added

- **Trade Portal, first part: a simple shop portal at `/trade`** (docs/tasneem-samples/PLAN.md, phase 1 — first company: Tasneem Knitting Industry). It is switched on per company by a Super Admin (Companies → edit → **Trade Portal**), on the main domain, with the usual staff sign-in.
  - **Bangla first**, with বাংলা / English at the top (and on the sign-in page). Made for a phone: big cards, big text, a tab bar at the bottom (Home · Business profile · Menu); the Menu sheet has the language, the company list (when a person has more than one) and Sign out. Light theme.
  - **Home** shows four cards: today's sales, dues to collect (with the customers who owe most), low stock (products at or below their reorder level) and the **LC files** still open (purchases with an LC number whose goods have not arrived; "LC opened" or "Shipped (invoice received)" once the Commercial Invoice number is in). Each card has a friendly line when there is nothing yet.
  - **Business profile** page for the owner: registered trade address and factory / letterhead address, phone, e-mail, TIN, IRC, **two BINs** (one for the import papers, one for the Delivery Challan — empty means the same), signatory, bank accounts and a letterhead image. The same fields are also on the ERP's company page.
  - New editable role **Trade Owner** (Users → Roles) and two permissions: **Trade Portal: Open** and **Trade Portal: Edit the Business Profile**.
- **Trade Portal, second part: shop work** (PLAN.md phase 2). New tabs at the bottom of the phone: Home · Sales · Stock · Dues · Menu, and four big buttons on Home: New challan, Receive money, Goods in, New item.
  - **Delivery Challan (the sale).** Write the challan like the pad: SL no. (the next pad number, changeable), date, buyer (from the list or new), lines with description, quantity, unit, remarks, and a price only on the lines sold for money — a machine set carries the price, the stand, feeders and CAMs given with it carry none. Advance (date, note), cash paid (date), where the money went, cheque no. and bank, when the due will be paid, who signs. The total and the due are worked out while you type (7,50,000 × 2 = 15,00,000; 1,00,000 + 8,00,000 paid → 6,00,000 due).
  - Saving it is the sale: the goods leave the stock (refused, naming the item, when there is not enough), the due goes onto the buyer, and the money paid is recorded.
  - A challan's page shows the lines, every payment and the due now, with **Print** (A4, laid out like the pad, with the letterhead image or the business name and factory address, the challan BIN, the money block and the two signatures, in English like the pad), **Receive money** and **Cancel challan** (the goods go back to stock and the due is removed).
  - **Dues**: every buyer who owes money, most first, the total to collect, and **Receive money** against one of their challans or against the old due.
  - **Stock**: every item with how many are left (low ones marked), **New item** with the count on the shelf, and **Correct count** with a reason.
  - **Goods in**: enter a purchase in short form (supplier — new ones can be added on the spot, date, LC no., items with quantity and buying price) and press **Goods arrived** when it comes, or tick "already in the shop"; the stock goes up then.
  - **Bring in old data**: buyers with their old due, and items with their stock, from a CSV or Excel file, each with a sample file. One wrong row stops the whole file and the rows to fix are listed.
  - **Business profile** has a new box for the text printed under the challan (your disclaimer lines, as on your pad).
  - New permissions for roles: Delivery Challans, Goods In, Stock, Dues, Bring In Old Data; the Trade Owner role gets them all.
- **Trade Portal, third part: cash book and balance sheet** (PLAN.md phase 3). Menu → Cash book, Balance sheet, Cash and bank accounts; a "Cash and bank now" card on Home.
  - **Cash and bank accounts**: add the cash box, bank and bKash accounts with the money in each today; the list shows the money in each now.
  - **Cash book, one day**: money at the start of the day, every line of the day in words (sale — challan no. and buyer, old due received, paid to supplier, the cost and what for, money moved between accounts, bank charge), money in, money out, money at the end, and each account at the end of the day. Back / forward and a date to jump to any day.
  - **Cash book, one month**: money at the start and the end, sales received, old dues received, paid to suppliers and costs for the month, the costs by kind, and each day's in and out (tap a day to open it).
  - Buttons on the cash book: **Bank deposit / move money** (cash to the bank, the bank to cash, any two accounts), **Pay a supplier** (lists who is owed and how much), **Other cost** (pick what for, or type a new name like "Truck fare"), **Receive money** (opens Dues).
  - **Balance sheet**: what the business has today (cash in hand, bank and mobile banking, stock at buying price, what buyers owe), what it owes suppliers, and what is left. Bank loans and the LC margin are not in the system yet and say so.
  - Money moved between your own accounts is shown in the book but is neither money in nor money out.
  - Money received now always says which cash or bank account it went into (with a challan, on a challan's page and in Dues), so every taka shows in the cash book.
  - When the system refuses something (not enough stock, an account would go below zero, more than the due, more than a supplier is owed), the portal now shows why in a red notice and keeps what you typed. Before, some of these refusals showed nothing.
  - New permissions: Cash Book and Balance Sheet, Cash and Bank Accounts; the Trade Owner role gets both.
- **Trade Portal, fourth part: LC file and import papers** (PLAN.md phase 4). Menu → LC files.
  - **One LC file per purchase** (enter the PI as a purchase in Goods in first). One form holds everything the papers need: PI no. and date, LC value, delivery terms, description of goods (with brand and model), used machines or not, country of origin; the supplier's company name, address and contacts; LC no. and date, the opening bank as written on the letter, the account (can be filled from the Business profile's bank accounts), margin % and the Taka paid, the bank's trade office and its BIN; insurance company and marine cover note; inspection agency and date; ports, carrier, vessel, ETD / ETA, B/L, container and seal, packages, and the **net and gross weight of this shipment** (never global); Commercial Invoice no. and date and the papers' dates.
  - **Stages**: PI received → LC opened → Under inspection → On vessel → Port clearance → Cleared, shown as numbered steps with a "Move to" button; and where the documents are at the bank: Awaiting docs / Discrepancy pending / Released. The Home LC card shows each file's stage.
  - **Five papers as A4 PDFs**, with the wording and fixed clauses of the owner's samples: **LC Opening Letter** and **Discrepancy Release Letter (DRL)** on your letterhead; **Beneficiary's Certificate**, **Detail Packing List** and **Shipment Advice** with the supplier's name at the top. Each paper shows what is still missing until it can be printed; a **Shipment Advice is never printed without the marine cover note no. and date**.
  - New permission: LC Files; the Trade Owner role gets it.
- **Trade Portal, fifth part: landed cost and machine serial numbers** (PLAN.md phase 5).
  - **Landed cost** (button on each LC file): the goods in Taka at the bank's rate (LC value × Taka per US Dollar), then every cost of the import with where the figure came from — bank charges / LC commission / SWIFT, marine insurance, customs duty / AIT / ATV / RD, port and shipping line charges, C&F commission and clearing, inspection (PSI) fee, truck fare, loading and unloading, or any other named cost. Duty, insurance and C&F are marked "C&F agent", truck fare and loading "Owner", by default; the source can be changed. The page shows the total landed cost, the **landed cost per unit**, and per item when one LC brings several kinds of goods (shared by their value).
  - These costs **do not change the purchase total or what the supplier is owed** — they are paid to customs, the C&F agent and the trucker, not to the supplier. **Apply to stock buying price** writes each item's landed cost per unit to its buying price (only when pressed), so the stock value in the balance sheet and the profit use it.
  - **Machine serial numbers** (optional): write them on the LC file (one per line, never more than the machines on the purchase); when a machine is sold, tick its serial number on the challan's page. The challan page and the printed challan show "S/N: …" under the machine's line; a cancelled challan frees its serial numbers. Menu → Serial numbers finds any machine: which LC it came on, in the shop or which challan and buyer.
  - New permission: Landed Cost; the Trade Owner role gets it.
- **Trade Portal, sixth part: reports** (PLAN.md phase 6). Menu → Reports, nine reports, each on screen (a table on a computer, one card per line on a phone) with **Print**, **PDF** and **Excel** buttons:
  - **Daily sales** (each challan / order with buyer, phone, items and serial numbers, total, received, due, when the due will be paid) and **Monthly sales** (day by day).
  - **Dues by age**: each buyer's due split into 0–30, 31–60, 61–90 and over 90 days since the sale, plus the old due from the old books.
  - **Stock ledger**: per item, opening + received − sold ± corrections = closing, and its value at the buying price (the landed cost once applied).
  - **Owed to suppliers**, with how many of their LCs are still open.
  - **Cash and bank**: per account, opening, money in, money out, closing for any period, and the LC margins the bank holds.
  - **LC status** (every LC with PI, value, margin, CI, B/L, container, vessel, ETA, documents and stage), **Landed cost per shipment**, and the **Shipment advice and insurance register**.
  - **Print** opens the report in the portal's language for the printer or "Save as PDF" (Bangla is drawn correctly). **PDF** downloads a PDF file in English. **Excel** downloads the same table with amounts as numbers.
  - New permission: Reports; the Trade Owner role gets it.
- **Phone check of the whole portal**: every page opened at 360px (a small Android phone) — no page scrolls sideways; the from–to dates of the period reports now wrap instead of running off the screen.
- **Trade Portal on a phone looks and works like an app** (owner request 2026-10-23, with the owner's mobile reference design: navy and soft blue, round cards, clear places for everything).
  - **Home**: "Good morning," and the name; **today's sales** on a big navy card with a **New challan** button and an arrow to all sales; **At a glance** — dues to collect, cash and bank now, low stock, open LC files — each a box you can tap; **Quick jobs** as round buttons with their names (New challan, Receive money, Goods in, New item); then who owes money, what is running low and the open LC files, each with "See all".
  - **Every other page** opens under a navy header with a round **back** button (a list goes back to Home, a form or a challan to its list, a report to Reports) and the page's buttons in white.
  - **Every list is a stack of cards**: the main name in bold on top (a sale shows the buyer first), then "name … value" lines, a round arrow when the card opens the record, and buttons such as **Receive money** at the bottom. The search box takes the whole row.
  - **The bar at the bottom floats**; the open page is a navy pill with its name. **Menu** shows the other pages in three groups — Accounts and reports, Import and goods, Setup — three to a row with the icon above the name.
  - **Reports** is a list of rows with an icon and an arrow.
  - Buttons are navy instead of bright blue. Desktop keeps its top menu; the new Home cards sit side by side there.
- **Trade Portal: Bangla in Tiro Bangla, a one-line desktop menu and a new sign-in page** (owner request 2026-10-23).
  - All Bangla text in the portal is drawn in **Tiro Bangla** (English and digits stay in Hind Siliguri). The font is part of the app, so it works without the internet.
  - **Desktop menu fixed**: the 13 links no longer wrap onto a second line over the business name. The bar shows Home, Sales, Stock, Dues and three dropdowns — Accounts and reports, Import and goods, Setup — with the open page as a navy pill; on smaller laptops the icons hide so it stays on one line.
  - **Sign-in page**: a navy panel beside the form on a computer ("Your shop in one place" and three things the portal does), a navy band above the form on a phone, a white rounded card with large fields and a round navy button.

### Fixed

- **A customer's total due now changes the moment a payment is recorded on one of their orders.** Until now the customer's due only caught up on the next change to the order itself, so the Customer Due card, the customer list and the due alerts could show money that was already paid.

### Security

- **A person whose role only gives Trade Portal permissions can no longer open the ERP admin (`/admin`).** Until now every active staff account could open every staff panel. Roles with any other permission (all existing roles) keep `/admin` exactly as before. A staff account is now refused by any panel other than `/admin`, `/office` and `/trade`.
- After signing in on `/trade`, a leftover "go back to" address from another panel (for example an `/admin` page) is ignored, so the sign-in always lands in the Trade Portal.

### Technical Notes

- Trade Portal: `TradePortalPanelProvider` (panel `trade`, guard `web`, `AdminLogin`, theme `resources/css/filament/trade/theme.css`), pages in `app/Filament/Trade` (`Home`, `BusinessProfile`, `TradeNavigation`), `TradePortalCompanies` (who opens which company; `trade.access` + `companies.trade_portal_enabled`; session key `trade_portal_company_id`, link `?trade_company=<id>`), `ResolveTradePortalCompany` (sets `CompanyContext` before `SubstituteBindings`, also a persistent middleware), `SetTradePortalLocale` + `GET trade/language/{locale}`, `TradePortalHome` (each card in `ModuleGuard::run()`; reuses `ReportService::salesTotals()` (new), `CustomerDueAlertService`, `LowStockAlertService`, `Purchase`). Views in `resources/views/filament/trade`.
- `User::canAccessPanel()`: `trade` → `TradePortalCompanies::mayEnter()`; `admin` → `! isTradePortalOnly()` (role permissions all `trade.*`; `*` and empty lists are not); other panels → false. `RestrictPortalHosts::MAIN_HOST_PATHS = ['trade']` serves `/trade` on every non-portal host (an IMS / reseller host still redirects it). `ImsPanelSessions::forgetIntendedUrlOutside()` is used by `AdminLogin` on the trade panel.
- Migration `2026_10_22_100000_add_trade_portal_columns_to_companies_table`: `companies.trade_portal_enabled` (default false), `registered_address`, `factory_address`, `challan_bin_number`, `bank_accounts` (JSON), `letterhead_path`; no index. Inserts the `trade_owner` row in `user_roles` (`trade.access`, `trade.profile.manage`) only when missing; no company or business data is created. `Company::tradePortalIsOn()`, `Company::challanBin()`.
- Fix: `CompanyNotificationObserver` builds its "View company" link for the `admin` panel explicitly (saving a company from another panel threw `RouteNotFoundException`).
- 42 new keys in `lang/en.json` + `lang/bn.json`. Test: `TradePortalTest` (14). `docs/roles-and-permissions.md` and `docs/codebase-map/core-platform.md` updated.
- Trade Portal phase 2: migration `2026_10_23_100000_create_delivery_challans_tables` (`delivery_challans` with unique `(company_id, challan_number)`, `delivery_challan_items`; index names explicit and short) also adds `trade.sales`, `trade.purchases`, `trade.stock`, `trade.dues`, `trade.import` to the `trade_owner` role when it exists. Models `DeliveryChallan`, `DeliveryChallanItem` (`BelongsToCompany`, in `MultiCompanyIsolationTest`). `DeliveryChallanService` makes an `Order` with `source = trade_challan` (`Order::SOURCE_TRADE_CHALLAN`; status completed, delivery delivered, `shipping_zone = none`, fee 0), `OrderItem`s for product lines (stock through `OrderWorkflowService`), `OrderPayment`s for advance / cash; `receivePayment()` (`OrderPayment`), `receiveOldDue()` (`CustomerPayment`, needs a money account), `cancel()` (order cancelled). `OrderStatusSmsService` sends nothing for `trade_challan` orders. Trade resources in `app/Filament/Trade/Resources` (`DeliveryChallans`, `GoodsIn`, `StockItems`, `Dues`), page `ImportOldData` + `TradeImportService` + `App\Support\SpreadsheetCsv` (first sheet of .xlsx/.ods to CSV for `CustomerCsvService` / `ProductCsvService`), print route `trade/challans/{challan}/print` (`TradeChallanPrintController`). Portal stock lists and challan item choices leave out the ERP's coming-soon cost placeholders; the home low-stock card too. Trade lists are stacked cards on phones (`AppServiceProvider`). Panel colour is Filament's `Color::Blue`. Challan print text: `companies.settings.trade_challan_terms`.
- `Order::recalculatePaidAmount()` now calls `syncCustomerBalance()` after updating paid/due (the fix above).
- 131 new keys in `lang/en.json` + `lang/bn.json`. Tests: `DeliveryChallanServiceTest` (7), `TradePortalShopWorkTest` (8). Codebase map: `core-platform.md`, `sales-orders-couriers.md`.
- Trade Portal phase 3: `TradeCashBookService` reads `TransactionLedger` of the manual accounts (no new ledger or table); entries through `FundTransfer` + `FundTransferService::approve()` (ledger rows re-dated to the chosen day; a move that would take an account below zero is refused), `SupplierPayment`, `Expense` (+ `ExpenseCategory::createWithUniqueSlug()`). Pages `CashBook`, `BalanceSheet`, resource `MoneyAccounts` (`Account::manual()`), Home card via `TradePortalHome::money()` (guarded). Migration `2026_10_23_110000_add_cash_book_permissions_to_trade_owner_role` adds `trade.cashbook` and `trade.money_accounts` to `trade_owner` (no schema change). Trade-only roles still stay out of `/admin`.
- `DeliveryChallanService`: an account is required when an advance or cash is paid with a challan, and for `receivePayment()`. `App\Filament\Trade\TradeErrors::attempt()` wraps every portal action and create page so a `ValidationException` from a service/model becomes a danger notification and the action halts. The refusal messages of `Expense`, `SupplierPayment` and `CustomerPayment` now go through `__()` (English text unchanged).
- Trade Portal phase 4: migration `2026_10_24_100000_create_lc_files_table` (`lc_files`, unique `(company_id, purchase_id)` and `(company_id, stage)` index, explicit short names; adds `trade.lc` to the `trade_owner` role when it exists). Model `LcFile` (`BelongsToCompany`, in `MultiCompanyIsolationTest`; `Purchase::lcFile()`), `LcFileService` (form split over purchase / supplier / file), resource `LcFiles` (list, create, edit, view), `TradeLcDocumentController` + views `resources/views/trade/lc/*` (DomPDF, the ERP's purchase-paper styles), route `trade/lc-files/{lcFile}/papers/{document}`. `TradePortalHome::lcStage()` uses the LC file's stage when there is one. Badges and buttons in portal lists no longer shrink.
- 70 new keys in `lang/en.json` + `lang/bn.json` (2 phase-2 help texts replaced). Tests: `TradeCashBookServiceTest` (5), `TradePortalCashBookTest` (4), plus updates to the phase-2 tests. Codebase map: `core-platform.md`, `accounts-finance.md`.
- Phase 4: 99 new keys in `lang/en.json` + `lang/bn.json`. Test: `TradeLcFileTest` (9). Codebase map: `core-platform.md`, `catalog-inventory.md`.
- Phase 5: migration `2026_10_25_100000_create_landed_cost_and_machine_serial_tables` (`lc_files.exchange_rate`, `lc_files.landed_cost_applied_at`, tables `landed_cost_lines` and `machine_serials` with explicit short index names; adds `trade.landed_cost` to `trade_owner`). Models `LandedCostLine`, `MachineSerial` (both in `MultiCompanyIsolationTest`), `LcFile::landedCostLines()`, `DeliveryChallan::serials()`; services `LandedCostService` (worksheet, `addLine()`, `setRate()`, `applyToStock()`), `MachineSerialService` (`addToPurchase()`, `optionsFor()`, `setForChallan()`, `releaseChallan()` — called from `DeliveryChallanService::cancel()`). The ERP's purchase cost fields and `PurchaseWorkflowService::syncLandedCosts()` are untouched (they feed the purchase total and supplier due). Resource page `LcFiles/Pages/LandedCost`, resource `MachineSerials`, "Serial numbers" actions on the LC file and challan pages, S/N on the challan page and print. 53 new keys in `lang/en.json` + `lang/bn.json`. Test: `TradeLandedCostTest` (8). Codebase map: `core-platform.md`, `catalog-inventory.md`.
- Phase 6: `TradeReportService` (nine reports, one shape), pages `Reports` / `Report` (Livewire `#[Url]` type / from / to), `TradeReportDownloadController` (route `trade/reports/{report}/download/{print|pdf|xlsx}`), views `resources/views/trade/reports/{print,pdf}`, `App\Support\TradeReportFormat`. DomPDF cannot shape Bangla (checked: vowel signs misplaced), so the PDF file is English and the Bangla copy is the browser print page (the investor reports' approach, shared print script). Migration `2026_10_26_100000_add_reports_permission_to_trade_owner_role` (no schema change). The stock ledger dates moves by `stock_movements.created_at` (there is no movement date). 34 new keys in `lang/en.json` + `lang/bn.json`. Test: `TradeReportsTest` (9). Codebase map: `core-platform.md`.
- Trade Portal phone look: `TradeNavigation::layout()` gains each page's Menu-sheet group (`GROUPS`, `groups()`), `TradeNavigation::backUrl()` reads the page being drawn (`Livewire::current()`, so a Livewire update keeps the same link) and `resources/views/filament/trade/back-button.blade.php` draws it at `PAGE_HEADER_HEADING_BEFORE` inside `ModuleGuard::run('trade-back-button')`. `Home::getHeading()` is empty (the view draws the greeting). Panel primary colour is a written-out navy OKLCH palette (a palette generated from the hex made 600 a mid blue and Filament then drew pale buttons with dark text). The card lists, navy header and floating bar are CSS in `resources/css/filament/trade/theme.css` (below 640px / 1024px). The challan list shows the buyer column first. No migration, model or route. 5 new keys in `lang/en.json` + `lang/bn.json`. Test: `TradePortalShopWorkTest` (+1).
- Trade Portal font / desktop / sign-in: panel font `Trade Text` (`@font-face` in the trade theme: Tiro Bangla woff2 for the Bengali range, Hind Siliguri Latin for the rest; files in `resources/fonts/tiro-bangla/` with `OFL.txt`, from @fontsource/tiro-bangla 5.2.5). `TradeNavigation::build()` puts the non-tab pages into `NavigationGroup`s (same groups as the phone Menu sheet). `resources/views/filament/trade/auth-side.blade.php` at `SIMPLE_LAYOUT_START`. 5 new keys in `lang/en.json` + `lang/bn.json`. Test: `TradePortalTest` (sign-in page).
## [2.107.0] - 2026-10-23

**Release type:** Minor Feature Update

### Added

- **SMS Dashboard (Settings → SMS Dashboard).** Owner request 2026-10-23: see how many SMS went, how many failed, what it cost and what is left on the BulkSMSBD account without logging in to BulkSMSBD. It shows, for today / the last 7 days / the last 30 days / this month: **SMS sent**, the SMS **parts** used (a Bangla or long message takes more than one), **refused by the gateway** (with a link to the SMS Log for the reason), **not sent by our own rules** (no wording, opted out; these cost nothing), the messages still waiting in the queue, a split by kind of message, and a day-by-day bar list. The **balance on BulkSMSBD** is read live from BulkSMSBD's balance API (refresh button, kept for five minutes), with a red warning when it falls below the amount you set, and a **Recharge on BulkSMSBD** button that opens their site. **Limits, set by BulkSMSBD and not by us:** its API has no recharge, no delivery report and no price list, so a recharge is still made on their site, "sent" means the gateway accepted the message (not that the phone received it), and the cost is an estimate (parts × your price per SMS), not BulkSMSBD's own charge.
- **Price per SMS** and **Warn me when the balance is below** on Settings → Integrations → SMS Gateway. The first is your own price (the cost is not shown until you enter it); the second drives the low-balance warning.
- **Every SMS is now counted, not only order SMS.** Login codes, recovery codes, abandoned-cart and lead reminders, broadcasts and investor/IMS messages were not logged at all. They now leave a row in the SMS Log of kind "Other messages" with the phone number, the result, the gateway's answer and the parts used. The text is **never** stored, so a login code does not sit in the database.

### Fixed

- **The test SMS said "1001 Invalid Number" for a correct phone number.** Owner report 2026-10-23 (the API key was already accepted by then). BulkSMSBD's own example writes the number as `number=88017XXXXXXXX`, so a gateway URL saved as `number=88{phone}` sent the country code twice (`888801...`). The system always sends the number as `8801XXXXXXXXX` and now notices an `88` or `880` written in front of `{phone}` in the URL and does not repeat it. When the gateway refuses an SMS, **Send test SMS** now also shows **what was sent**, the gateway address with the number and sender ID but with the API key and the message text hidden, and the advice for code 1001 says to write `number={phone}` with nothing in front.
- **A connection error could put the SMS API key on screen and in the SMS Log.** When the gateway could not be reached, the request's error message (which names the whole gateway URL, API key included) was saved in the SMS Log's detail column and the application log. Gateway URLs are now removed from those messages before they are saved or shown, and the same is done to rows an earlier version already wrote. If a gateway connection error ever appeared in your SMS Log, consider replacing the BulkSMSBD API key (Developers page) as a precaution.

### Technical Notes

- `StorefrontNotificationService`: `smsRequestUrl()` builds the gateway address (one place for the send and the preview, with the `88{phone}` / `880{phone}` rule), `smsRequestPreview()` hides the key (also when it is typed into the URL) and the text; `sendSmsDetailed()` returns it as `request`. Tests: `SmsDashboardTest` (+2).
- SMS dashboard: `App\Filament\Pages\SmsDashboard` (Settings cluster, "manage settings" permission), `SmsUsageService` (aggregates the SMS log; the company scope applies), `SmsBalanceService` (BulkSMSBD hosts only, host taken from the saved URL template, answer parsed from a `balance` field or a bare number, anything else shown as it came; the real reply for a valid key could not be checked here), `App\Support\SmsSegments`. `sms_logs.segments` (migration `2026_10_23_210000`, backfills older rows from their text and scrubs gateway URLs from old `detail` values); `SmsLog::KIND_OTHER`; `StorefrontNotificationService::sendSms()` now writes the `other` row through `SmsLogService::other()` and `sendSmsDetailed()` also returns `segments`; `scrubUrls()`. `sms_price_per_sms` and `sms_low_balance_alert` join `SMS_CREDENTIAL_KEYS` (kept by both Integrations and Storefront Settings). Tests: new `SmsDashboardTest` (13). Lang: 41 new keys.
## [2.106.0] - 2026-10-23

**Release type:** Minor Feature Update

### Changed

- **The order page has a Payment section of its own.** Owner request 2026-10-23: a new person could not tell where an advance payment goes, because the paid amount sat among the totals as a plain "Paid Amount". On the order form (create and edit) **Totals** now ends at the **Grand total**, and a separate, always-open **Payment** section below it holds the **Advance / paid amount**, the **Payment Method** (the account the money went into) and the **Due amount**, each with a line saying what to enter. When editing an existing order the advance is added in the **Payments History** table under the form, and the line says so. The order's view page shows the same **Payment** block (advance / paid amount and due amount) apart from **Totals**.

### Fixed

- **Courier booking asked the courier to collect the whole grand total even when the customer had paid an advance.** Owner report 2026-10-23: with a ৳200 advance on a ৳560 order the courier still got ৳560, so the customer would have paid the advance twice. **Book courier** (the order's own button, the orders list and the bulk action) now sends the **due amount**: grand total minus the payments received, for example ৳360, and ৳0 when the order is fully paid. The grand total still includes delivery, with the customer's zone delivery fee added when the order has no shipping fee of its own. A line under the COD field shows the sum ("Grand total ৳560 − advance paid ৳200 = ৳360"); the amount stays editable per booking. This replaces the 2026-10 rule that sent the full total.
- **The reseller landing page could come up with no layout on a phone.** Owner report 2026-10-23 (a screenshot of reseller.zamzamint.com with an enormous logo, the language switch shown twice and no spacing). The page took its layout classes from the site-wide `app.css`, which gets a new file name on every deploy; when that one file did not load, the page's own stylesheet still did, so the colours and borders showed but the layout fell apart. The landing page now carries its **own** stylesheet with exactly the layout classes it uses (24 KB, 6 KB compressed, instead of the 252 KB site-wide file), so it no longer depends on `app.css` at all.
- **The landing page scrolled sideways on a phone.** At phone widths a long line (the store address in the "Your own store" picture) pushed one column about 54 px wider than the screen, so the browser zoomed the whole page out. Every grid on the page and in the website footer now starts as one column that may shrink, so long text is cut with "…" or wraps. Checked at 280, 320, 360, 375, 390, 412, 600, 768, 1024 and 1280 px, in English and in Bangla: no sideways scroll at any of them.

### Technical Notes

- `Order::courierInvoiceTotal()` (grand total incl. the zone-fee fallback, the old `courierCodAmount()` body) and `Order::courierCodAmount()` = that minus `paid_amount`, floored at 0. `CourierService::codHelperText()` feeds the helper text of `cod_amount` in `ViewOrder` and `OrdersTable`. `OrderForm` / `OrderInfolist` split Totals and Payment. Tests: `CourierIntegrationTest` (due-amount rule, advance + later payments, popup end to end), `OrderFormTest` (Payment section holds `paid_amount`, `payment_account_id`, `due_amount`, Totals does not). Lang: 10 new keys.
- `resources/css/reseller-landing.css` starts with `@import 'tailwindcss' source(none);` and three `@source` lines (the guide view, `reseller/partials/*.blade.php` and the shared social icon partial); `guide.blade.php` loads only this file through `@vite`. A new class in another view that the landing includes needs that view added to those lines. The grids use `grid-cols-1` as the base and `minmax(0, …)` for the hero's two columns. Tests (`ResellerLandingPageTest`): the page links its own stylesheet and not `app.css`, every view it includes is scanned, and every grid has a base column count.
## [2.105.0] - 2026-10-23

**Release type:** Minor Feature Update

### Added

- **Email Log: was the email sent, did it fail, and why.** Owner request 2026-10-23. New **Email** tab on **Settings → Logs** lists every email the ERP tries to send, for the selected company: time, the kind of message (customer login code, reseller password reset link, investor code, investor invitation, investor message, owner's daily digest, test email), who it went to, the result and the way it was sent (the site's own SMTP host, or the server's default mailer).
  - **Sent**: the mail server (for example Amazon SES) accepted the message. The page says this does not prove the inbox received it; bounces and spam-folder delivery are not tracked yet.
  - **Failed**: with a plain reason and the mail server's own answer: wrong SMTP user name or password, an email address that is not verified (the From address, or the recipient while SES is still in its sandbox), sending limit or rate reached, mail server unreachable, invalid address, or any other refusal.
  - **Not sent**: no email account is set up for the site, or the server's own mailer only writes to a log file so nothing left the server. A reseller password reset link or an investor code that could not be sent for this reason is now logged too.
  - Filter by result or kind of message. Staff who can manage settings see only their own company's rows. The text of a message is never stored, only who it went to and the result, so a login or reset code never sits in the log.
  - The **Send test email** button on Integrations → Email Setup also writes to this log.

### Technical Notes

- New table `email_logs` (migration `2026_10_23_200000_create_email_logs_table.php`, short index name) and company-owned model `EmailLog` (registered in `MultiCompanyIsolationTest`). `EmailLogService` writes rows, never throws (a failed write is reported and ignored, so logging cannot stop an email), and `classify()` maps the server's error text to a reason key (`auth_failed`, `address_not_verified`, `limit_reached`, `unreachable`, `invalid_address`, `send_error`); `not_configured` and `log_only` are written by the sender itself.
- `StorefrontNotificationService`: the three senders (`sendMailable()`, `sendResellerPasswordResetEmail()`, `sendLoginOtpEmail()`) now share one `deliver()`; the failure warning logged to `laravel.log` is unchanged (its context now always includes the mail class). `sendMailable()` takes the message kind as an optional last argument (IMS code, invitation, message, owner digest at their call sites). `sendTestEmail()` logs too. A send through a server mailer that is `log` or `array` still returns true as before but is logged as not sent (`log_only`).
- `OtpService` and `ResellerPasswordResetService` log the "no email account" case before returning, since they stop before any send.
- New read-only resource `EmailLogResource` (`canManageSettings()`), shown as the `email` tab of the Logs page (listed in `LogCatalog`, no sidebar entry of its own, route `/admin/settings/email-logs` kept; one-column phone layout like the SMS log), `docs/codebase-map/crm-messaging.md` updated, `docs/email-ses-setup.md` gains a section on the log. Lang keys in `lang/en.json` and `lang/bn.json`.
- Tests: new `EmailLogTest` (sent / failed / log-only rows, no code stored, the reason mapping with SES answers, logging failure never stops the email, company isolation, the page, the two early-return call sites, the Integrations test button); `ResellerPasswordResetByStaffTest` asserts the skip row; `MultiCompanyIsolationTest` lists `EmailLog`.
## [2.104.0] - 2026-10-23

**Release type:** Minor Feature Update

### Added

- **One "Logs" page for every log.** Owner request 2026-10-23. **Settings → Logs** is now the only log entry in the menu, with one tab per log, the way **Integrations** gathers the integrations: **SMS**, **Audit**, **Courier status**, **Courier webhooks**, **Meta events**, **Deploy errors** and **Mobile crashes**. A tab appears only for people who could already open that log, so nobody gains or loses access. The separate sidebar entries for SMS Log, Audit Logs, Deploy Error Logs and Mobile Crash Reports are gone; the logs' own pages and links (for example the link in a deploy-error alert, or Courier → Status Logs) keep working. The open tab is kept in the address, so a link opens the right log. The SMS log reads as one column on a phone (when, number, result, why) so it does not scroll sideways.

### Changed

- **Reseller landing page: the parcel answer names the company and its couriers.** Owner wording 2026-10-23. The answer to *How is the parcel sent, and who collects the money?* now reads "<company name> sends the parcel to your customer by courier (Steadfast, Pathao, RedX) on cash on delivery …" (the company name comes from the company's own settings; the three courier names are part of the page's text, in English and in Bangla).

### Fixed

- **"User Id not found" (code 1011) from BulkSMSBD now says what to do.** The SMS Log and the Send test SMS button explain each gateway reply in plain words next to its code: 1011 means BulkSMSBD does not recognise the API key, so copy it again from the BulkSMSBD Developers page; the same for sender-ID problems (1002, 1013-1015), an empty balance (1006, 1007), Bangla-only masking (1012), IP whitelist (1032) and an account that needs attention (1018, 1031).
- An **empty SMS API key** is now caught before the gateway is called and logged as "The SMS API key is empty".
- **Spaces copied with the key**, sender ID or URL are removed when the settings are saved and when an SMS is sent. The **SMS API key** box also asks the browser not to fill it in: a browser can put the saved login password into a password-type box, which the gateway then answers with "User Id not found".

### Technical Notes

- Logs page and SMS gateway hints: `App\Filament\Pages\Logs` (Settings cluster, needs the "manage settings" permission) builds its tabs from `App\Support\LogCatalog`, which lists the log resources and keeps those whose `canViewAny()` passes. Each tab embeds `App\Livewire\LogTable` (lazy, `#[Locked] $log`, re-checked on mount) which reuses the resource's own `table()` on the model's company-scoped query; a resource's `ViewAction` becomes a plain link to the resource's view page, and a resource may add a `logNotice()` (SMS and courier webhooks use it for the "queue worker not running" warning). The four resources set `$shouldRegisterNavigation = false` and keep their routes. `StorefrontNotificationService::sendSmsDetailed()` now also returns `reason` (`no_gateway`, `no_api_key`, `gateway_unreachable`, `gateway_refused`) and `smsGatewayHint()` maps codes to advice. Tests: `LogsPageTest` (8), extra cases in `SmsLogTest`. No migrations.
## [2.103.0] - 2026-10-23

**Release type:** Minor Feature Update

### Added

- **Amazon SES setup for the ERP's email.** Owner request 2026-10-23 (the domains are approved in AWS SES). SES is used through its SMTP interface, so it plugs into **Settings → Integrations → Email Setup** like any SMTP account:
  - **Amazon SES region (quick setup):** type the region (for example `ap-southeast-1`) and the SMTP host (`email-smtp.<region>.amazonaws.com`), port 587 and TLS are filled in. The region is not stored; it is read back from the saved host.
  - **Send test email:** sends a message through the settings saved for the site being edited and shows the mail server's own answer when it fails (for SES: unverified sender, sandbox recipient, wrong credentials or region).
  - **From address reminder:** an SES SMTP user name is an access-key ID (`AKIA…`), not an email address. If the saved account has no From address and its user name is not an email address, saving shows a warning, and the From address help text says so.
  - Step-by-step guide: `docs/email-ses-setup.md` (what to do in the SES console, what to enter here, common errors, the optional server-wide fallback in Coolify).

### Changed

- **The email (SMTP) settings moved from Storefront Settings to Settings → Integrations → Email Setup.** Owner request 2026-10-23. SMTP host, port, encryption, username, password, From address and From name are now edited on a new **Email Setup** tab next to **SMS Gateway**, per site (use the Site picker, like Payment Gateway and SMS Gateway). Values already saved keep working — nothing needs to be re-entered. Saving Storefront Settings no longer touches the email keys. The WhatsApp reminder fields stay on Storefront Settings → Customer Notifications & Reminders.
- The message shown to staff when a reseller's password-reset link cannot be sent because email is not set up now points to **Settings → Integrations → Email Setup**.

### Fixed

- **A company's email could go out through another company's SMTP account and sender.** The mail manager keeps the mailer it built for the whole process, so a long-running queue worker sending for one company after another (IMS and investor emails) kept using the first company's SMTP host, login and From address. Each send now builds the company's own mailer. With several domains on one SES account this would have sent a company's mail under another company's From address.
- The sender no longer falls back to an SMTP user name that is not an email address (it used to, which made every message fail); it uses the From address, else an email-shaped user name, else the server's default sender.

### Technical Notes

- Email keys (`mail_host`, `mail_port`, `mail_encryption`, `mail_username`, `mail_password`, `mail_from_address`, `mail_from_name`; new `StorefrontNotificationService::MAIL_CREDENTIAL_KEYS`) still live in the encrypted `storefront_settings.notification_credentials`; no migration. `Integrations::save()` merges only the SMS and email keys into the stored array (`ownedNotificationKeys()`), and `EditStorefrontSetting::mutateFormDataBeforeSave()` re-adds both sets so the Storefront Settings form, which no longer has these fields, cannot wipe them.
- `StorefrontNotificationService`: new `mailFromAddress()` (From address, else an email-shaped user name, else null), `sendTestEmail()` (returns null or the server's error text, never throws); `companyMailer()` now calls `Mail::purge('storefront_dynamic')` before building the mailer and uses `mailFromAddress()`.
- In this Laravel version the SMTP transport ignores the `encryption` setting: port 465 connects over SSL (`smtps`), any other port is plain SMTP that upgrades with STARTTLS when the server offers it. The SMTP encryption box therefore does not change how the connection is made; the tab says so. A site with no encryption saved (or a saved null) is shown as TLS; None is saved as an empty string.
- Lang: new keys for the tab, the SES quick setup, the test email and the From address warning; the Storefront Settings section note, the From address help text and the "Email is not set up…" message changed text in `lang/en.json` and `lang/bn.json`.
- `.env.example` points to `docs/email-ses-setup.md` for the server-wide fallback mailer (`MAIL_*`); nothing else in the environment changes.
- Tests: new `CompanyEmailSetupTest` (5: sender rules, per-company mailer isolation — fails without the `purge` — and the test email); `IntegrationsPageTest` (+7, one rewritten); `ResellerPasswordResetByStaffTest` expects the new message.
## [2.102.0] - 2026-10-23

**Release type:** Minor Feature Update

### Added

- **Reseller landing page: the questions new resellers ask, answered.** Owner request 2026-10-22, from a real prospective reseller's questions. A new **Questions resellers ask** section (with a link chip in the page's menu) answers six questions in plain words, each answer taken from what the system really does, not from promises: can I just market and sell while the store packs and delivers; can I list the products on my Facebook page and website with photos, details and prices; what do I send when a customer confirms an order (nothing: you enter the order in the portal and the form shows the customer's bill and your profit); how the parcel is sent (cash on delivery, nothing in advance) and what the customer's bill is made of; what is deducted from the money collected (your profit is your selling price minus your cost; the delivery charge and VAT are added on top; no separate packaging charge on orders the store delivers; the return charge only when the store has set one and the amount only when *Show money figures on the guide* is on); and when and how the profit is paid (payable after delivery, withdrawal request to bKash, Nagad, Rocket or a bank account, the store reviews and pays, the Earnings page shows every order).
- **Reseller landing page in Bangla, with a language switch.** Owner request 2026-10-22. The landing page now has an **English | বাংলা** switch in its header (and above the first screen on phones). The choice is kept in a cookie for a year, and the portal's sign-in and application pages follow it (they also carry the same small switch), including the portal's own Livewire requests. Nobody who never used the switch sees any change: they get the app's default language as before; the shop and the admin are never touched. Words the admin typed (the guide's introduction and rules) stay as entered; everything else is translated.

### Changed

- **Reseller landing page is set in Anek Bangla.** Owner request 2026-10-22. The whole page, Bangla and English alike, uses the Anek Bangla font (SIL OFL 1.1, variable weights 100–800), self-hosted with the ERP so no third-party font request is made; the landing page no longer loads Hind Siliguri or Unbounded.

### Technical Notes

- New `App\Http\Middleware\SetResellerPortalLocale` (cookie `reseller_portal_locale`, values `en` / `bn`, set by `?lang=`): runs in the `web` group right after `RestrictPortalHosts` and in the reseller panel's middleware and `persistentMiddleware` (after `ResolveCompanyFromResellerPortalDomain`, so Livewire updates keep the language); it does nothing unless the request is on a reseller portal host (`reseller_portal_company` attribute). Views: `resources/views/reseller/partials/language-switch.blade.php` (landing) and `resources/views/filament/reseller/language-switch.blade.php` (sign-in / application pages, panel render hooks). The language names are `Language name: English` / `Language name: Bangla` and are read in their own language (`__('...', [], 'bn')`), so the switch always shows "বাংলা". 18 new keys in `lang/en.json` and `lang/bn.json`.
- Font files under `resources/fonts/anek-bangla/` (Bengali, Latin and Latin-extended subsets of the variable font, plus `OFL.txt`) and their `@font-face` rules in `resources/css/reseller-landing.css`. **Deploy: `npm run build`.** Tests: `ResellerLandingPageTest` (+7: the questions and answers, figures following the settings, the Bangla version and cookie, the language kept on the landing and application pages, an unknown language ignored, the shop untouched, the font shipping with the ERP).
## [2.101.0] - 2026-10-23

**Release type:** Minor Feature Update

### Added

- **SMS Log: you can now see why a customer got no SMS.** Owner report 2026-10-23: an order was placed and no SMS arrived, and nothing in the system said why. **Settings → SMS Log** now lists every order SMS (order placed and each status change) with its result:
  - **Sent**, with the exact text that went out.
  - **Not sent: gateway**, with the gateway's own reply (for example `1012 Masking SMS must be sent in Bengali`, `1007 Balance Insufficient`, `1032 IP not whitelisted`) or "could not be reached".
  - **Not sent: skipped**, with the reason: no wording written on SMS Templates, customer has no phone, number opted out, no SMS gateway set, already sent once, imported or reseller store order.
  - **Waiting in the queue**: when a message waits more than 5 minutes the page says the queue worker does not seem to be running, which is the usual reason nothing leaves in production.
  Code messages (OTP, password reset) are never logged, so a code is never stored.
- **Send test SMS** button on **Settings → Integrations → SMS Gateway.** It sends one real SMS to your own number through the saved gateway and shows the gateway's answer on screen, so "does the gateway work?" is a ten-second check without placing an order. With a masking (letters) sender ID the test text is sent in Bangla, because BulkSMSBD refuses English there. The result also appears in the SMS Log.
- A note on the SMS Gateway tab explaining that the URL only delivers messages: the wording is written on **Settings → SMS Templates**, and the branding comes from the sender ID and that wording.

**Technical Notes:** New table `sms_logs` (migration `2026_10_23_100000_create_sms_logs_table.php`, short index names) and company-owned model `SmsLog` (registered in `MultiCompanyIsolationTest`). `SmsLogService` writes the rows and never throws (a logging failure is reported and the SMS still goes out; covered by a test). `OrderStatusSmsService` logs a row when the message is queued, or a skipped row at dispatch when no wording is written, and closes it in `send()` / `sendPlaced()`; the existing send rules and dedupe are unchanged. `StorefrontNotificationService::sendSmsDetailed()` returns the gateway's code, its meaning and any error; `sendSms()` still returns a bool. `SmsLogResource` is read-only and needs the "manage settings" permission. New strings in `lang/en.json` and `lang/bn.json`. Tests: `SmsLogTest` (13). No other behaviour changes.
## [2.100.1] - 2026-10-22

**Release type:** Security Update

### Security

- **PHP update path for the critical PHP vulnerabilities of 2026-10-21 (CVE-2026-109618 and CVE-2026-109619, remote code execution without logging in).** The ERP container's PHP was 8.4.2 (December 2024): Coolify's Nixpacks builder keeps an old package snapshot, so a plain redeploy would never bring the fixed version. The repository now has a production `Dockerfile` on the official `php:8.4.27-fpm` image (PHP 8.4.27 is the fixed version). Nothing changes on the live server until the owner switches the ERP app's Build Pack in Coolify from Nixpacks to Dockerfile (steps in `docs/deployment.md`). The old Nixpacks setup is untouched and stays the rollback.
- The image also turns off PHP's upload-progress feature (`session.upload_progress.enabled=0`), one of the ways the second vulnerability is reached, and recycles PHP-FPM workers every 500 requests.

### Technical Notes

- New: `Dockerfile` (stages: PHP runtime + extensions `bcmath exif gd imagick intl opcache pcntl pdo_mysql zip`, Composer, Vite assets, final image), `.dockerignore`, `docker/` (`nginx.conf` = the Nixpacks template as plain nginx, `php.ini`, `php-fpm-pool.conf` with `clear_env = no`, `supervisord.conf`, `entrypoint.sh`).
- `entrypoint.sh` fixes the owner of the persistent `/app/storage` volume once (files from the Nixpacks image belong to another user), links `public/storage`, then starts nginx + PHP-FPM, or the queue worker when `CONTAINER_ROLE=worker` (the worker app uses the same image).
- `PHP_VERSION` in the `Dockerfile` is pinned to `8.4.27` and must be raised on each PHP security release.
- New workflow `.github/workflows/docker-image.yml` builds the image and checks: PHP >= 8.4.27, required extensions, nginx + FPM serving the app from a root-owned empty volume (`/health.html`, Laravel `/up`, container environment visible to PHP, storage writable by the web user), and the worker role running as `www-data`.
## [2.100.0] - 2026-10-22

**Release type:** Minor Feature Update

### Added

- **The order summary card now shows each product's picture.** On the order page, **Order summary card** draws a small picture beside every item, so the customer sees the very product they ordered. It uses the variant's own photo when the ordered variant has one, otherwise the product's main image. An item with no picture (or one that cannot be read) gets a plain grey box so the rows stay lined up. The pictures are part of the image sent to WhatsApp, WeChat, Messenger and Telegram, and of the downloaded image. "Copy text" is unchanged.

**Technical Notes:** `OrderSummaryCardService` adds `image` to each item: the picture is read through `CompanyStorageService::readPublic()`, cropped to a square, shrunk to 160 px and returned as a JPEG data URI (needs PHP GD; without it the card has no pictures). It is embedded so the browser canvas stays untainted and can still be exported. `summary-card.blade.php` decodes the pictures before drawing (`loadImages()`) and draws rounded thumbnails in the items table. New tests in `OrderManagementFeaturesTest` (picture present and 160x160; none when the product has no picture or the file is gone). No migrations, no new lang keys.

### Changed

- **The order summary card has a corporate, enterprise-style layout.** Owner request 2026-10-22: the card looked generic. It is now laid out like a business document:
  - **Header:** the company logo on the left (the company name in large type when there is no logo) and the company's phone, e-mail, website and address on the right. The company details appear only here, not again in the footer.
  - **Title band:** "Order summary" with the invoice number, under a dark navy band with an orange line.
  - **Two detail boxes side by side:** **Order details** (invoice, date, status, courier, tracking, and a coloured payment badge: Paid in full, Partially paid or Payment due) and **Customer details** (name, phone, address).
  - **Items table** with a dark header row (Item, Price, Qty, Amount), the product picture, the unit price and striped rows.
  - **Note box** on the left (the order note, when there is one) and **totals** on the right, ending in a large **Due** band: orange when something is owed, green when the order is paid in full.
  - **Footer:** the thank-you line and a navy strip.
  - A reseller's order still shows only the reseller's store name and logo, retail prices and no company contact details, as on the invoice.

**Technical Notes (layout):** `OrderSummaryCardService::build()` now returns `logo` (PNG data URI, shrunk to fit 520x160, transparency kept), `contact` (hotline from the invoice settings else company phone, e-mail, website), `address`, `invoice`, `order_rows` / `customer_rows` (each with a `key`), `payment` (`paid` / `partial` / `unpaid`), `note`, per-item `price`, and `labels`; it no longer returns `meta` or `company_phone`. Reseller orders return no contact or address. `summary-card.blade.php` draws the whole card on the canvas from this data (new `loadImages()` also loads the logo). New strings (Customer details, Paid in full, Partially paid, Payment due, Payment) are in `lang/en.json` and `lang/bn.json`. Tests: two new cases in `OrderManagementFeaturesTest`; the reseller tests now check `contact` / `address` instead of `company_phone`. No migrations.

### Added

- **The customer is told about every order automatically.** Owner request 2026-10-22.
  - **SMS when an order is created.** A new message, **Order placed**, on Settings → SMS Templates (Order status tab). It can use `:items` (product names with quantities) as well as the order number, status, total and due. It follows the same rules as the status SMS: nothing is sent until the owner writes or saves the wording ("Use suggested wording" fills it in), never for reseller orders, imported past orders, customers without a phone or on the opt-out list. It waits about 45 seconds in the queue so the items are saved first. The existing status SMS also gets `:items`.
  - **The order summary card on WhatsApp, by itself.** New page **Settings → Order WhatsApp card**. When switched on, the customer is sent the card as a picture on WhatsApp when the order is created and each time it reaches a new stage (confirmed, processing, shipping, delivered, completed, cancelled, returned, refunded). It goes only through the official WhatsApp Cloud API (a Cloud API or Coexistence channel) with an approved template that has an **Image** header and exactly five body variables in this order: customer name, order number, status, total, amount due. The page lists only templates like that. A QR-linked WhatsApp Web channel is never used for automatic messages (`docs/whatsapp-web-rules.md`, rule 4). WhatsApp cannot be asked beforehand who has an account, so every customer with a phone is tried and a number without WhatsApp is simply skipped; the SMS is separate. Reseller orders, imported past orders and opted-out customers are never sent. The page also says whether this server can draw the card picture.

**Technical Notes (automatic messages):** `OrderStatusSmsService::dispatchForOrderPlaced()` / `sendPlaced()` and `SendOrderPlacedSmsJob` (delay `PLACED_DELAY_SECONDS`, one send per order via a cache lock); `SmsTemplateService::ORDER_PLACED` (`order.placed`) and the `items` placeholder. `OrderWhatsAppCardService` (queued by `Order::created` and `OrderStatusTransition::created`, both wrapped so a failure never undoes the order or status change) renders the card with the new `OrderSummaryCardImageService` and sends it through `MetaGraphService::uploadWhatsAppMedia()` (picture upload, no public link needed) and `sendWhatsAppTemplate()` (new `$headerImageId`). `listMessageTemplates()` now also returns `has_image_header` and `body_variables`. Settings live in `companies.settings['order_whatsapp']` (`OrderWhatsAppSettings`); no migration. **Server requirement:** the card is drawn with Imagick through ImageMagick's Pango coder, which joins Bangla letters correctly (GD and Imagick's own text drawing do not); on Debian/Ubuntu this is the package `libmagickcore-6.q16-6-extra`. `OrderSummaryCardImageService::canRender()` checks the coder and a Bangla shaping probe; without them no card is sent and the reason is recorded on the channel and shown on the settings page. The bundled Hind Siliguri font is registered through a private fontconfig file in `storage/app/order-card-fonts`. Tests: `OrderWhatsAppCardTest` (8), two new cases in `SmsTemplatesTest`. Failed WhatsApp sends are logged and shown as the channel's last error, never retried automatically.
## [2.99.0] - 2026-10-22

**Release type:** Minor Feature Update

### Added

- **Reseller landing page at the portal address, ending with the website's footer.** Owner request 2026-10-22. `reseller.<company domain>` (or the company's own reseller portal domain) now opens the reseller landing page itself instead of going straight to the sign-in page: how the program works and the company's rules, **Sign in** and **Apply now** buttons in the header, the first screen and a closing banner, and at the end the **footer of the company's Next.js website** — its Footer Builder blocks, footer menu, social links and background colour, with links to the website's pages built on the website's domain. A link back to the reseller program is left out of it, since the footer sits on that page. A company with no Next.js website keeps the simple copyright line, and without a website domain only absolute links (not links to the website's own pages) are shown. The page is also still at `/portal/guide`. **Resellers → Reseller Settings → Show the reseller guide** controls it: off, the address goes straight to the sign-in page as before. The storefront's *Become a reseller* links now point at the portal address itself.
- **Website footer: "Become a reseller" is a banner button.** Owner request 2026-10-22. The *Become a reseller* menu item is now sent to the Next.js site together with a banner picture (`image_url`), and the website's footer shows that picture as a call-to-action button above its columns instead of a text link (it lifts a little on hover and a light sweeps across it now and then; both stop for visitors who prefer less motion). The header keeps the text link. The picture is the owner's "Reselling Program — Start Your Online Business Without Investment" banner, which ships with the ERP; **Reseller Settings → Website banner button** lets the admin upload their own, and clearing it brings the standard banner back. The matching website change is on the website repo's `feature/reseller-menu-link` branch.
- **Reseller landing page redesigned in the neo-brutalist style.** Owner request 2026-10-22 (the owner found the first design plain and chose neo-brutalism from a short list of trending styles). The page keeps every word, rule, figure and button it had — only its look changes: a warm cream page with a dot grid, thick black outlines, flat bright colour blocks (yellow, pink, mint, sky, orange next to the store's own brand colour) and hard offset shadows that lift on hover and press flat on click; heavy headlines (Unbounded for English, Hind Siliguri for Bangla and body text); a "price − cost = profit" card in the first screen; a scrolling yellow ticker of the four steps; coloured step cards; an expandable rules list; and a bold closing banner. It follows the visitor's light or dark setting, works on phones, and stops all motion for visitors who prefer less motion. Text on the brand colour is white or near-black, whichever is readable on the store's colour. The website's footer under it is unchanged.

### Technical Notes

- Reseller landing page: `RestrictPortalHosts` lets the reseller host's `/` through while `Company::resellerLandingEnabled()` (the guide switch) is on and remembers the portal company on the request (`reseller_portal_company`); `ResolveCompanyFromDomain` in `optional` mode leaves such a host alone; `HomeController` sets the company and hands the request to `ResellerGuideController`. `Company::resellerJoinUrl()` returns the host root while the landing page is on (the application form otherwise). The footer is `StorefrontSiteConfigService::footer()` (blocks grouped into columns, links made absolute on the website's domain, colour darkness like the website's `isDarkColor`) drawn by `resources/views/reseller/partials/footer.blade.php`, built inside `ModuleGuard` so a footer problem only costs the page its footer. Banner: migration `2026_10_22_100000_add_reseller_banner_image_to_storefront_settings` (`storefront_settings.reseller_banner_image`, nullable, on the Blade row), `StorefrontSiteConfigService::resellerBannerUrl()`, the standard picture at `public/images/reseller-program-banner.png`. Tests: `ResellerLandingPageTest` (9); `StorefrontApiTest` and `ResellerGuidePageTest` updated for the new address. **Deploy: `php artisan migrate`, `npm run build` (new Tailwind classes in the footer partial), and deploy the website branch for the button.**
- Neo-brutalist landing page: new Vite entry `resources/css/reseller-landing.css` (the `.nb-*` component classes and the self-hosted Hind Siliguri faces; Unbounded comes from `app.css`), loaded after `app.css` by `resources/views/reseller/guide.blade.php`. Those rules are not in a cascade layer, so they beat Tailwind's utilities: do not put a conflicting utility (display, background, border) on the same element, wrap it. `ResellerGuideController` also passes `onBrandColor` (`StorefrontSiteConfigService::isDarkColor()`, now public static, decides white or near-black text on the brand colour). No migration, no new text (no lang keys). Test: `ResellerLandingPageTest` (+1). **Deploy: `npm run build` (a new CSS entry); a deploy without it fails on `@vite` until the manifest has `reseller-landing.css`.**
## [2.98.0] - 2026-10-22

**Release type:** Minor Feature Update

### Added

- **IMS office on a phone works like an app.** Owner request 2026-10-22 (the owner's mobile reference designs). On phones and tablets the office (`/office`) now has:
  - **A tab bar at the bottom**: Home · Investors · a round green **+** · **To do** · **Menu**, with names under every icon. The menu button in the top bar is gone on phones; everything is in the bar.
  - **+** opens the things staff add (add an investor, new project, and the others the person is allowed to add).
  - **To do** opens "What to do today" from any page; a red number on the tab says how many things are waiting.
  - **Menu** shows every page as big tiles in the same groups as the top menu (with their waiting numbers), the English / বাংলা switch, light / dark, the company list and Sign out.
  - **Home**: initials, "Good morning!" and the name at the top, then the green card, then four round buttons — Confirm money, KYC, Payouts, Support — each with how many are waiting.
  - **Every list is a stack of cards** on a phone: the first column in bold as the card's title, then "label … value" lines and the buttons at the bottom, instead of a table that had to be scrolled sideways. The investor portal's lists do the same.
  - Desktop and laptop screens are unchanged.

### Technical Notes

- `OfficeNavigation::mobile()` builds the bar, the "+" list (`canAccess()` + `canCreate()` + a create page), the to-do (`OwnerTodo`) and the menu tiles; `resources/views/filament/ims/office/mobile-nav.blade.php` draws them at `BODY_END` through `ModuleGuard::run('ims-office-mobile-nav')` — if it fails nothing is drawn and the top-bar menu button comes back (it is hidden only while `.ims-tabbar` is on the page). `Home::quickActions()` (guarded too) feeds the round buttons; the welcome header takes `app: true` for the phone greeting.
- Lists: `Table::configureUsing()` in `AppServiceProvider` sets Filament's `stackedOnMobile()` for the `office` and `investor` panels only; the card look is in `resources/css/filament/ims/theme.css` (below 640px).
- The sheets lock the page by a veil with `touch-action: none` rather than `overflow: hidden` on the page, which made phones jump/zoom in testing. No migration, no new model or route. 10 new keys in `lang/en.json` + `lang/bn.json`. Test: `OfficeHomeTest` (+1).

## [2.97.0] - 2026-10-21

**Release type:** Minor Feature Update

### Added

- **Purchases → Import purchases from CSV**: upload a CSV and the purchases are created for you (owner request 2026-10-21, shaped like the Stock History sheet).
  - Pick the **supplier** and the **status** in the upload box. The status starts as **Draft**, which changes no stock and no supplier balance; **Received** adds the quantity to stock at once, so use it only for goods that are not already in stock.
  - Columns: `date`, `product` (the product name) or `sku`, `quantity`, and optionally `unit_cost`, `short_qty` and `damaged_qty`. **Sample CSV** downloads a file with these headings.
  - **One purchase per date**: all rows of one date become the items of one purchase. A blank date takes the date of the row above, as merged cells do in a spreadsheet export. Dates such as `2026-02-21`, `21/02/2026` and `08/28/2025` are understood; a date like `10/09/2025` that could be day/month or month/day is read day first, and the result tells you how many were like that.
  - A product is found by SKU, then by its exact name. A blank `unit_cost` uses the product's cost price.
  - A row that cannot be used (product not found, more than one product with that name, bad date or quantity, short plus damaged above the quantity) is **left out and listed**; nothing is guessed and no product is created.
  - **Preview only** shows what would be created without saving. Uploading the same file again does not create the purchases twice.

### Fixed

- **The Items section of the purchase form is laid out in rows again.** Every field of an item had been squeezed into one long row. It is now three rows: Product, Quantity, Short Qty, Damaged Qty; then Unit Cost, Subtotal, Allocated Cost, Landed Unit Cost; then HS Code, Spec / Model Note, FOB Price, Net Weight, Gross Weight. On a phone the fields stack one under another.

### Technical Notes

- `App\Services\PurchaseCsvImportService` holds the rules (see its class comment); the Purchases list page action is `ListPurchases::importPurchasesAction()` and the sample is `GET /admin/purchases/import/sample` (`purchases.import.sample`, `PurchaseCsvController`). A created purchase carries `[csv:<12 hex>]` in its note, made from the supplier, the date and the typed lines, so a second upload of the same file is recognised and skipped. No migration, no new model. Tests: `PurchaseCsvImportTest` (10).
- `PurchaseForm`: the items repeater no longer uses `->table()`; it is a 12-column grid (`columns(['default' => 1, 'md' => 12])`) and each field takes its share with `itemSpan()`.
## [2.96.1] - 2026-10-21

**Release type:** Patch/Fix Update

### Changed

- **Reminders never go out days late.** The scheduled jobs are about to be switched on in Coolify for the first time, and many reminders were waiting. So that customers are not all messaged at once about old things: an abandoned cart left more than 3 days ago (on top of the company's reminder delay) gets no SMS/WhatsApp; a lead follow-up that was due more than 3 days ago still reaches the staff member but sends nothing to the lead; and a sales follow-up (checkout link or quotation reminder) that was due more than 3 days ago is cancelled with the reason "Too old", not sent. Recent ones work as before. Owner decision 2026-10-21.

### Technical Notes

- Maintenance: `.claude/settings.local.json` (each machine's own Claude Code permissions) is git-ignored.
- Reminder age limits: `STALE_AFTER_DAYS = 3` on `SendAbandonedCartReminders` (carts with `updated_at` older than the delay + 3 days are skipped and stay unreminded), `SendLeadFollowUpReminders` (the staff notification and `follow_up_reminded_at` as before; `messageLead()` only when `next_follow_up_at` is within 3 days) and `Crm\SalesFollowUpService` (`process()` cancels a pending row whose `due_at` is older than 3 days). `docs/deployment.md` → Scheduler now has the Coolify scheduled-task steps. No migration, no new strings. Tests: `StorefrontResellerAndAbandonedCartTest`, `LeadFollowUpReminderTest`, `SalesFollowUpScheduleTest` (+1 each).
## [2.96.0] - 2026-10-21

**Release type:** Minor Feature Update

### Added

- **Admin: Reseller sales report.** Owner request 2026-10-16. **Resellers → Reseller sales report** shows one row per reseller for a chosen period (this month by default; last month, last 7 / 30 days, this year, all time, or your own dates): orders, cancelled and returned orders, sales, the reseller's cost, the reseller's profit split into *payable* (delivered) and *pending delivery*, and what was paid out in the period. Cards above the table give the totals, and a column can be sorted or hidden. Only confirmed, processing and completed orders count as sales (the rule the reseller's own dashboard uses); a cancelled or returned order is only counted. A reseller's name opens their page. Read-only; visible to staff who can view reseller profits, and only while the company's reseller module is on.
- **SMS to resellers: approved, profit payable, withdrawal paid.** Owner request 2026-10-16. A reseller now gets an SMS when their application is approved, when a profit becomes payable (the order was delivered; one SMS per order) and when a withdrawal is paid. The text of each message is written by the company in **Reseller Settings → SMS to resellers** (up to 300 characters; words such as `:name`, `:order`, `:profit`, `:amount`, `:reference`, `:balance`, `:portal` and `:store_link` are replaced with the reseller's own details) and **starts empty: a message with no text is never sent**. A button, *Fill empty SMS texts with suggested wording*, puts a draft in the empty boxes; nothing is saved or sent until Save changes is pressed. SMS goes through the company's SMS gateway (Settings → Integrations → SMS Gateway); numbers that opted out are skipped, and the same profit or withdrawal is never messaged twice. Messages are sent from the queue, so a slow gateway cannot hold up an order, a courier webhook or a payment, and a failed send is only logged. WhatsApp is not used (see UPDATE_NOTES).
- **Next.js site: the "Become a reseller" menu item works.** Owner request 2026-10-16 (open item found while checking the website). The menu builders (header, footer, top bar, footer link columns) already offered a *Become a reseller* item, but the website API sent it without an address and the Next.js site left it out. `GET /site` now gives that item `url` = the reseller portal's guide (or application form, when the guide is off) — the same address the Blade store uses — and drops it while the company's reseller module is off. The website needs its small matching change (`menuHref` accepts the item), pushed to the website repo's `feature/reseller-menu-link` branch.
- **Reseller levels.** Owner decisions 2026-10-16. **Resellers → Reseller levels** is one list with pop-up forms where the company makes up its own levels (any number, any names, for example Bronze, Silver, Gold), each with an order in lists and, optionally, its own **return charge** and its own **minimum withdrawal**; an amount left empty means the company's amount from Reseller Settings applies, and 0 means free. A reseller's level is set **by hand**: on the reseller's edit page (a level of that reseller's own company only), in bulk with *Set level* on the reseller list, and the list has a *Level* column and filter. The reseller sees the level name as a badge on their portal dashboard. A returned order is charged the level's return charge (the level the reseller has at the time of the return) and the Withdrawals page, and the withdrawal check on the reseller store, use the level's minimum. Nothing else changes with a level: no prices, no profit, no commission. Deleting a level puts its resellers back on the company amounts and changes no order or money.
- **Purchase module: auto-generated, printable PI / CI / Packing List documents.** A purchase's own info (supplier, items, China-to-BD costs, and a new Trade Documents section — delivery terms, country of origin, ports, freight, HS Code, FOB price, net/gross weight) now drives three formal trade documents instead of manual paperwork: Proforma Invoice, Commercial Invoice, and Packing List, laid out to match the business's existing bank/supplier templates. Each is generated on demand from the Purchase page ("Generate PI/CI/PL"), which fills in the document's number/date the first time (derived from the purchase number, editable) without overwriting anything already entered, then opens a print-ready PDF in a new tab. PI is available as soon as the purchase has a supplier and items; CI and Packing List unlock once Delivery Terms, Port of Loading, and Port of Discharge are filled in. Company (BIN/IRC/TIN, authorized signatory) and Supplier (country, bank/beneficiary details, signature/stamp) now carry the extra fields these documents need, and Company Settings → Purchase Documents holds the admin-editable default wording (Payment Terms, Terms & Conditions, Packing List certification note) each purchase can override.
  - Regression coverage: `tests/Feature/PurchaseDocumentTest.php` (PDF routes for all three document types, permission check, document-number auto-fill behavior, FOB/CFR/weight totals used on the printouts).

### Fixed

- **WhatsApp Web: a picture or voice note whose file was lost from the server is saved again** when the bridge sends it again (for example after the number is linked again and the history is re-sent). Before, the ERP skipped it because the message still pointed to a file that no longer existed.
- **"Add shipment plan" on a Purchase never actually created a shipment.** Every attempt silently failed validation ("Purchase must belong to the same company."), no matter what was filled in. `Shipment`'s own-company consistency check ran in a `saving` hook, but on a brand-new record `saving` fires *before* `creating` — the hook that actually fills in `company_id` — so the check was always comparing the container's/purchase's real company against a still-blank `company_id`, which never matched. The check now compares against the company_id the shipment is actually about to be saved with (falling back to the same company-resolution `BelongsToCompany` itself uses) instead of the not-yet-set field, and still correctly rejects a container or purchase from a genuinely different company.
  - Regression coverage: `tests/Feature/ShipmentTest.php` (a shipment plan — with and without a container — can now be created; a container or purchase from another company is still rejected).

### Security

- **A CAPTCHA now answers a *challenged* sign-in, so a real person on a new device can still get in while an account or the whole site is being attacked.** Until now a challenge was only a wait (so the owner of an account under attack had to wait on a new device). With **Cloudflare Turnstile** set up, the sign-in page shows the check and the visitor signs in once it is solved. A normal sign-in never sees it.
  - **Settings → Login Security** (super admin): switch it on and paste the Turnstile **site key** and **secret key**. The secret key is stored encrypted and is never shown again; a blank field keeps the saved key. Get the keys free at dash.cloudflare.com → Turnstile → Add widget (add every domain people sign in on, "Managed" mode).
  - Shown on the **storefront login form**, the **admin, reseller and investor sign-in pages**, and answered by the **Next.js API** as `429 captcha_required` with the public site key (the website sends the solved token back as `captcha_token`; until it does, its visitors see the same error as before and nothing breaks).
  - It only ever **adds** a way through for a person: it does not lift a device that keeps guessing or the "client that only ever fails" denial, it does not skip the password, and a rejected token, Cloudflare being unreachable or a half-filled setting leave the plain wait in place.

### Technical Notes

- Purchase branch `claude/purchase-module-automation` (2026-09-03/04, merged 2026-10-21): `PurchaseDocumentController` + route `purchases.documents`, `PurchaseDocumentActions`, `App\Support\NumberToWords`, views `purchases/documents/{pi,ci,pl}`; five migrations add trade-document columns to `companies` (BIN/IRC/TIN, signatory, signature), `suppliers` (country, bank/beneficiary details, signature), `products` (`hs_code`), `purchases` (PI/CI/PL numbers and dates, delivery terms, ports, freight, exchange rate, terms) and `purchase_items` (HS code, spec note, FOB price, weights); `CompanySettingsService::purchaseDocuments()` holds the default wording. `Shipment`'s same-company check uses `Company::resolveActiveCompanyId()` when `company_id` is still empty on a new record. On merge every admin string the branch added was wrapped in `__()` (63 new keys in `lang/en.json` / `lang/bn.json`); the printed documents stay English. Tests: `PurchaseDocumentTest`, `ShipmentTest`. **Deploy: `php artisan migrate`.**
- Reseller branch `claude/reseller-module-status` (merged 2026-10-21). Reseller sales report: new `ResellerSalesReportService` (`rows()` / `totals()`, reads `Order`, `ResellerCommission` and `ResellerPayoutRequest` through their company scopes) and the `ResellerSalesReport` page in the Resellers cluster (table with `records()`, period + custom-date filters). No migration or new model. Damage source value `other` is unchanged; only its label is now *Warehouse damage* (lang key `Other damage` replaced). Tests: `ResellerSalesReportTest` (6).
- Reseller SMS: migration `2026_10_16_100000_add_reseller_messages_to_storefront_settings` (three nullable text columns `reseller_message_approved`, `reseller_message_profit_payable`, `reseller_message_payout_paid`); `ResellerNotificationService` + queued `SendResellerNotificationJob` (one try; restores the request's company context afterwards so the sync queue is safe); model hooks in `Customer` (status becomes approved), `ResellerCommission` (becomes payable) and `ResellerPayoutRequest` (becomes paid); each hook only calls `notify()`, which never throws. Tests: `ResellerNotificationsTest` (11). **Deploy: `php artisan migrate`, and a running queue worker for the SMS to leave.**
- `StorefrontSiteConfigService::menu()` resolves menu items of type `reseller` through `Company::resellerJoinUrl()` (`docs/api/storefront-api.md` updated). Test: `StorefrontApiTest::test_the_become_a_reseller_menu_item_points_at_the_reseller_portal_and_goes_when_the_module_is_off`.
- Reseller levels: migration `2026_10_16_110000_create_reseller_levels_table` (table `reseller_levels` with unique `(company_id, name)`, and `customers.reseller_level_id` nullable, `nullOnDelete`); model `ResellerLevel` (`BelongsToCompany`, added to the isolation test, mapped to the `sales` permission module in `User::MODEL_MODULES`); `Customer::resellerReturnCharge()` / `resellerMinWithdrawal()` are the one place the level-or-company amount is decided and are used by `ResellerOwnCourierService::chargeReturn()`, the portal `Withdrawals` page and `ResellerStoreController`. Tests: `ResellerLevelsTest` (10). **Deploy: `php artisan migrate`.**
- WhatsApp Web media (branch `claude/whatsapp-web-login-sync`, merged 2026-10-21): `WhatsAppWebArchiveService::attachMedia()` returns early only when the stored file is still found (`CompanyStorageService::locatePrivate()`); otherwise it stores the bytes again under the same name. `docs/deployment.md` now says how to check that `/app/storage` is a persistent volume (`df -h /app/storage` must not show `overlay`). 2 new tests in `WhatsAppWebArchiveTest`.
- CAPTCHA branch `ccr-4505e72d-hwaq92` (2026-10-18, merged 2026-10-21). New: `App\Support\LoginCaptchaSettings` (global `AppSetting` keys `login_captcha.enabled`, `login_captcha.turnstile.site_key` / `secret_key`, both encrypted, same pattern as `FirebaseSettings`), `App\Services\TurnstileVerifier` (POST to Cloudflare's `siteverify` with the secret, the token and the visitor's IP; any failure counts as "not solved"), `App\Filament\Pages\LoginSecuritySettings` (Settings cluster, super admin) with its view, `App\Filament\Concerns\HasLoginCaptcha` (the Livewire part shared by `AdminLogin` and the reseller and investor `Login` pages) and `resources/views/filament/components/login-captcha.blade.php` (loads Turnstile's script on demand, writes the token to `$wire.captchaToken`, resets after each attempt). No migration.
- `LoginGate` takes a `TurnstileVerifier`: at the two `CHALLENGE` conditions (account failures, site-wide failures) a solved token clears the challenge and the token is verified at most once per attempt. `LoginClient` carries `captchaToken` (form field `cf-turnstile-response`, API field `captcha_token`); `LoginAttempt::requiresCaptcha()` and `LoginThrottled::$captcha` tell the callers whether to show the widget. The Blade controller flashes `captcha_required`; the login view renders the widget only then.
- 16 new interface strings in `lang/en.json` and `lang/bn.json`. Tests: `LoginCaptchaTest` (20: settings and encryption, the verifier against Cloudflare answers and failures, the gate, Blade, API and the three Filament pages). Also checked end to end in a real browser against Cloudflare's always-pass test keys (admin sign-in and the storefront form). `docs/login-protection.md` and `docs/api/storefront-api.md` §6 describe the setup and the API.
- On merge, the investor sign-in that tries every business of the phone (`InvestorBusinessSwitchService::attemptCentralLogin()`, built after the CAPTCHA branch) takes the page's `LoginClient` (new optional 4th argument) so a solved CAPTCHA counts there too, and shows the widget on a challenge; `AdminLogin` keeps the office/portal `mount()` from the sign-in fix next to the CAPTCHA form. Test: `LoginCaptchaTest` (+1, 21).
## [2.95.1] - 2026-10-21

**Release type:** Patch/Fix Update

### Added

- **Investor portal: Projects is a main page** (owner request 2026-10-21: a project is what the investor puts money in, company by company). It now sits right after Home in the top menu and in the menu on the left, and takes Feed's place in the phone's bottom bar (Home · Projects · Investments · Profit · Profile). Feed stays in the top menu and the left menu and moves into the phone's Profile list.

### Fixed

- **The office sign-in no longer sends you to the investor sign-in when the same browser also uses the investor portal** (owner report 2026-10-21, patch). On `zamzaminv.app` the investor portal and the office share the browser's one session, and "the page to go back to after signing in" was one address for both: a portal page opened while signed out of the portal (for example after signing out) was where the next **office** sign-in landed, which then showed the investor sign-in page. Each sign-in now goes back only to a page of its own panel, else to its own home (also when you are already signed in and open the sign-in page). Signing out of one also no longer signs you out of the other: the office and the portal can stay signed in side by side in one browser, and signing out of one clears only that one. With only one of them signed in, signing out clears the whole session as before. The ERP admin, reseller portal and app.zamzamint.com are unchanged.
- **IMS office and investor portal top bar no longer breaks on laptops.** On screens narrower than a large monitor (and much sooner in Bangla), the menu pushed the light/dark button, the bell and the profile button off the right edge or squeezed them. The buttons now keep their size, and when the menu does not fit beside them it gets its own line underneath (office up to 1800px wide, portal up to 1360px). On large phones and small tablets the office's company picker and language switch move into the menu drawer, as on small phones.
- **One light/dark switch.** The profile menu also had a light/dark/system switch that did not follow the moon button (it kept showing "light" after the page turned dark, and pressing "light" then did nothing). It is hidden in both IMS panels; the moon / sun button in the top bar is the switch.

### Technical Notes

- Office / portal sign-in in one browser: root cause was Laravel's shared `url.intended` session key (set by the investor panel's `Authenticate` for a signed-out visit, consumed by Filament's `LoginResponse` / `Login::mount()` of the office panel on guard `web`) and Filament's `LogoutController` calling `session()->invalidate()` (which dropped the other guard's sign-in). No middleware redirected investors; `RestrictPortalHosts` was not involved. New `App\Support\ImsPanelSessions` (IMS panels `office` / `investor` only: `forgetForeignIntendedUrl()`, `otherPanelSignedIn()`, `forgetPanelKeys()`), `App\Filament\Auth\PanelLoginResponse` (bound to Filament's `LoginResponse` contract) and `App\Http\Controllers\Auth\PanelLogoutController` (bound over Filament's `LogoutController`; while the other IMS guard is signed in it logs out only this guard, forgets this panel's keys - office: `ims_office_company_id`, `password_hash_web`; portal: business switch, link, staff preview, `password_hash_investor` - and regenerates the session id and CSRF token), both bound in `AppServiceProvider::boot()`. `AdminLogin::mount()` and the investor `Login::mount()` drop a foreign intended URL first. Other panels: no change in behaviour. No migration, no new strings, no settings change. Tests: new `Ims\OfficeAndPortalSessionTest` (8; every request starts with fresh guards so only the session carries over, as in a browser).
- IMS top bar and Projects (owner report 2026-10-21): `resources/css/filament/ims/theme.css` - `.fi-topbar-end > *` no longer shrinks; between 1024px and 1799.98px (office) / 1359.98px (portal) the top bar wraps (`::after` line break, nav `order: 3`, `--topbar-height: 7.75rem` so the rail and page start below it); office `<900px` shows the company picker and language switch only in the drawer; company picker name capped at 12rem below 1280px; Filament's user-menu theme switcher hidden (`.fi-dropdown-list:has(> .fi-theme-switcher)`). `PortalNavigation`: Projects moved from "More" to the top level after Home and into the rail's first group; `filament.investor.mobile-nav`: Projects replaces Feed; the Profile page list shows Feed instead of Projects. No migration, no new strings. Tests: `InvestorPortalTest` (1 changed, 1 new).
## [2.95.0] - 2026-10-20

**Release type:** Minor Feature Update

### Added

- **Every company runs on the one IMS domain** (`18_IMS_ALL_COMPANIES_PLAN.md` phase A). The IMS domain stays on the company that has it (today ZamZam International, `zamzaminv.app`); every other active company now uses that same domain, with no setting per company. Their investor invites, join links, partner sign-up links, staff "Open investor portal" links, monthly statements and messages point at `zamzaminv.app`, and each such link says which company it is for, so it opens that company's page there. A company's old investor-portal address now goes to `zamzaminv.app`. The IMS rules apply to every company from now on: KYC before new money, the ERP's **Investments** menu opening the IMS office (with that company open), and the daily IMS jobs (profit proposals, reminders, statements, owner digest, late shipments) run for every company.
- **Company picker in the IMS office** (phase B). Staff of any company sign in at `zamzaminv.app/office`. A **company button** at the top lists every company the person may open (on phones the list is at the top of the menu); choosing one keeps it until they choose another, and every office page then works on that company as before. The name and logo at the top stay the IMS domain's. ERP Super Admins and **office admins** open every company; anyone else opens only the companies they work for. "Office admin" is a new permission, **IMS: Office Admin (full access to every company in the office)**, that a Super Admin gives a role in Settings → Roles.
- **"All companies" page in the IMS office** (phase C). Anyone who may open more than one company finds **All companies** at the top of the company button (and of the phone menu). One page with big cards in plain words: **Total money invested** and **capital at work now** for all companies; **Profit to pay**; **Waiting for a decision** (the same list as "What to do today", from every company, each line with its company name); one card per **company** (money in, projects running, profit paid this month); every open and running **project** with its company; and the **new investors** this month. Tapping any line opens that company's own office page with that company open, where the usual buttons and permissions apply; the page itself approves nothing. Money is shown per currency and never added across currencies. Buttons at the top jump to Waiting, Companies, Projects and New investors.
- **Investor portal: projects from every company** (phase D). Someone who invests in more than one company (the same phone with an active account in each) now sees, on **Projects**, every project they put money in from every company in one list, newest first, each card with the **company name** in plain text; **Raising money now** also lists every company's open groups and projects. Tapping another company's project opens it after checking the person's own active account in that company (the portal then works in that company, as the business switcher does). The green balance card on **Home** adds up every company ("All your companies together"), per currency; money in another currency is shown on its own line, never added in.
- **Investor portal: Statement, Payouts, Documents and Requests from every company, with a Project filter** (phase E). Each list now shows the lines of every company the person invests in, newest first, each with its company's name (when they invest in more than one) and its own currency. One **Project** select (empty = all projects) narrows any list to one project. A download, payout report or payment screenshot of another company opens after checking the person's own account there. A **new request** (add money, withdrawal notice, payout choice) offers the groups, projects and payouts of every company and is sent to the company of the chosen one, as the person's own account there; reinvested money stays in the payout's company. **Cancel** on another company's request cancels it in that company. The printable statement and the monthly statement PDFs stay per company.
- **Forgot password and SMS-code sign-in work for every company on zamzaminv.app.** They looked only at the domain's own company; they now also find the person's account in another company that runs on the same IMS domain.
- **Plan: every company in one IMS on zamzaminv.app.** New `18_IMS_ALL_COMPANIES_PLAN.md`: all ERP companies run on the one IMS domain; the IMS office gets a company picker and an "All companies" home with the simplest UI (Super Admins and office admins see everything and grant role-based access); the investor portal lists projects from every company with the company name and a Project filter. All five phases (A-E) are built.

### Security

- The office's company is checked against the signed-in staff member on every request, never taken from the session or the link alone: a company they may not open is ignored. A link page (invite, join, sign-up, staff link) opens another company only when that company runs on this domain, and its token is still checked against that company.

### Technical Notes

- Plan 18 phase E: `InvestorPortfolio::statement()`, `payouts()`, `documents()`, `requests()` (each company read under its own context, rows carry `company_name`, `currency`, `project_key` = "<company id>:<project id>" and links through `link()`), `projectOptions()`, `choices()` / `splitChoice()` / `entryFor()` / `inCompany()`. New trait `App\Filament\Investor\Concerns\CombinedPortalTable` (Project `SelectFilter`, `pageOfRows()` paging of array rows). `Payouts`, `Documents` and `Requests` tables use `->records()` (array rows, key "<company id>-<id>") instead of a single-company query; `Statement` has a `#[Url] $project` select. `Requests`: every choice keyed "<company id>:<key>", `send()` checks that all choices of one request belong to the same company and creates it there with that company's account; cancel runs in the request's company. `InvestorPortalService` statement lines carry `project_id`; the statement rows partial shows each line's company and currency. No migration, no new strings. Tests: `AllCompaniesImsTest` (+5, 24); `InvestorPortalStaffEntryTest`, `InvestorPortalGroupViewTest`, `PayoutPaymentProofTest` and `InvestorGroupMoneyFlowsTest` use the new row keys ("<company id>-<id>") and choice keys ("<company id>:g<id>") on the portal pages.
- Plan 18 phase D: new `App\Services\InvestorPortfolio` (`entries()` = `InvestorBusinessSwitchService::businesses()` or just the current account for a staff preview / applicant; `summary()` per currency, `projects()`, `raising()`, `link()`; every read under its company via the new `CompanyContext::within()` and `ModuleGuard::run()`), new investor auth middleware `OpenLinkedInvestorBusiness` (a GET with `?business=<id>` switches through `InvestorBusinessSwitchService::switchTo()` - only to the person's own active account - then reloads the same page without the marker; anything else is ignored). `Projects` page uses the portfolio (`mine()` now returns rows with `company_name` and `url`; `openGroups()` / `openProjects()` replaced by `raising()`); `Dashboard::homeSummary()` / `otherCurrencies()` / `spansCompanies()`. `InvestorBusinessSwitchService::locateOnImsDomain()` (open company first, then other companies on the same IMS domain, each under its own context and `canAccessPanel`); `ForgotPassword` and `Login` (SMS code) keep the found account's company in a `#[Locked]` property for the second step; `Login` remembers the device under the account's company. `AllCompaniesFigures` uses `CompanyContext::within()`. 2 new strings. No migration. Tests: `AllCompaniesImsTest` (+5, 19).
- Plan 18 phase C: new `App\Ims\Office\AllCompaniesFigures` (`perCompany()` reads each company under its own `CompanyContext` - `OfficeFigures`, `OwnerTodo`, open/running projects, new investors - inside `ModuleGuard::run()`, then puts the page's context back; `totals()` per currency) and office page `App\Filament\Ims\Office\Pages\AllCompanies` (`/office/all-companies`, not in the menu, `canAccess()` = more than one company in `ImsOfficeCompanies::for()`), view `filament/ims/office/pages/all-companies`, "All companies" entry in the company picker, `.ims-all-*` CSS. Total money invested = confirmed group deposits + ungrouped investments (active or withdrawn). 16 new strings in `lang/en.json` / `lang/bn.json`. No migration. Tests: `AllCompaniesImsTest` (+3, 14).
- Plan 18 phases A + B. `Company::groupImsCompany()` (active, `ims_enabled`, `ims_domain` set; lowest id), `imsHost()` (own IMS domain, else the group's; null when inactive), `ownsImsDomain()`; `imsIsLive()`, `imsUrl()` and `investorPortalHost()` use `imsHost()`. `investorLinkUrl()` adds `?business=<id>` when the company does not own the domain (used by `InviteService`, `InvestorPortalAccountService` invites, `InvestorPortalStaffEntryService`, `ChannelPartner::inviteUrl()`); `imsOfficeUrl()` adds `?office_company=<id>` (used by `RedirectInvestmentsToImsOffice`, the ERP's Investments menu button, `ims:owner-digest`). `forRetiredInvestorPortalHost()` now covers any company that is on IMS. `ImsCompanyCommand` loops every active company with `imsIsLive()`. Investor side: `InvestorBusinessSwitchService::linkCompany()` (a page load reads `?business=`, Livewire posts read it from the session, `LINK_SESSION_KEY`); `ResolveCompanyFromCentralInvestorSession` uses it on business-only pages and, on other pages, opens the signed-in account's own company when it runs on this domain. Office: new `App\Services\ImsOfficeCompanies` (`for()`, `canOpen()`, `choose()`, `mayEnter()`, session key `ims_office_company_id`), new middleware `ResolveImsOfficeCompany` (after `AuthenticateSession`, also persistent), `User::canAccessPanel('office')` → `ImsOfficeCompanies::mayEnter()`, new permission `ims.all_companies`, view `filament/ims/office/company-picker` (top bar + drawer), CSS in `resources/css/filament/ims/theme.css`. The office brand is the IMS domain's company. 1 new string ("Choose company"). No migration. Tests: new `AllCompaniesImsTest` (11); `DomainRoutingTest`, `KycTest`, `CommunicationAndToolsTest`, `ExchangeRatesInOfficeTest` updated for the new rule.
## [2.94.0] - 2026-10-20

**Release type:** Minor Feature Update

### Added

- **The IMS domain is a sign-in for every business too** (owner request 2026-10-20). Investors only know the business's IMS address (for example `zamzaminv.app`), so its sign-in page now works like the central address: someone who also has an account in another business signs in there with phone and password, and the password of any of their accounts opens that business (the IMS domain's own business is tried first). The **Switch business** button then changes the business on the same address, without leaving it. Someone with an account in only this business signs in exactly as before (SMS code, password reset, sign-up and invites unchanged). The IMS office at `/office` is unchanged. The separate central address is no longer needed and can be left blank.

### Security

- On an IMS domain, another business opens only while the account signed in to that session belongs to it; otherwise (and always on sign-up, invite, password-reset and staff pages) the domain's own business stays open.

### Technical Notes

- IMS domain sign-in for every business: `InvestorBusinessSwitchService::imsHostCompany()`, `switchesInSession()`, `hasAccountsElsewhere()`; `attemptCentralLogin()` takes a preferred company. The investor `Login` uses the multi-business path on an IMS domain only in password mode and only when the phone has an active account in another business. `ResolveCompanyFromCentralInvestorSession` opens the chosen business on an IMS domain only when the session's `investor` guard account belongs to it. No migration, no new strings, no settings change. Tests: `InvestorBusinessSwitchTest` (+5, 21).

## [2.93.0] - 2026-10-20

**Release type:** Minor Feature Update

### Added

- **Orders still open in Nuport come in as live orders** (owner decision 2026-10-20: most of the 51 approved-but-never-shipped orders are real and are shipped from the ERP). In the **Import Nuport orders** dialog the new switch **Bring open orders in as live orders** (on by default; `--open-live` for the terminal command) imports every order that Nuport still has Approved or Processing and that has no courier parcel as a live order, source **Nuport (open order)**: it takes its stock out of the shelf, can be booked with a courier, tracked, texted and finished from the ERP like any order, and a later import file never changes it. Only the "New order received" alert and the risk check are skipped at import time, because the order is not new. The preview says how many open orders come in, how many pieces they take, how many were placed over 30 days ago (to check or cancel) and which products are short; a plain product's stock can go below zero, a colour's stock stops at 0. Orders that already have a courier parcel stay history. Turn the switch off to bring them in as history, as before.
### Fixed

- **A Nuport parcel's number is kept as the courier's consignment id, and a parcel still on its way is tracked here.** Owner decision 2026-10-20: the Nuport import is done once and Nuport is not used afterwards. The import saved Nuport's *Delivery ID* in the booking's *Tracking ID*, but it is a plain number (such as 167142542), the courier's consignment id, which the ERP keeps in the booking's reference (the tracking code is a different, alphanumeric code). It now goes in the reference. A parcel still on the way (in transit, or booked and not yet picked up) used to be left out of the courier bookings; with Nuport gone nothing would have updated it and staff could have booked it a second time. It is now recorded as an active booking: the order page says "Already booked", and Steadfast's status callbacks update it. The import preview says how many of the recorded parcels are still on the way.
- **Courier status callbacks find a booking by any id the courier sends.** The callback used only the first of the tracking code, the tracking id or the consignment id, so a booking known only by its consignment id was not found when Steadfast also sent a tracking code. It now looks for all of them.

### Technical Notes

- `NuportOrderImportService`: `bookingPlan()` records every parcel with a Delivery ID (no more final-status filter), `reference` replaces `tracking_id` (stored in `provider_reference`; `tracking_id` stays empty), the report counter `open_consignments` became `active_bookings`, and one summary string changed (":count of these parcels are still on the way; the ERP keeps tracking them.", `lang/en.json` / `lang/bn.json`). `couriers:sync-statuses` polls only bookings that have a tracking id, so imported parcels are updated by Steadfast's callback or by the Sync button (it falls back to the order number as the invoice); a stale-booking alert may list them, since they are old. `ProcessCourierWebhook` matches `tracking_id` or `provider_reference` against all of `tracking_code`, `tracking_id` and `consignment_id`. Open orders: `Order::SOURCE_NUPORT_OPEN` (`isHistoricalImport()` is false for it, `isFromNuport()` covers both sources); `OrderWorkflowService` lets its sale movements run negative like a WooCommerce order; `OrderNotificationObserver::created()` and the creation-time risk check in `Order::saved` skip it; `NuportOrderImportService::run(..., openAsLive:)` plans `live` = option + APPROVED/PROCESSING + no Delivery ID, reads the stock at the start of the run for the shortage list, and counts an existing `nuport_open` order as unchanged. Known limit (existing, `StockMovement::applyVariantStockDelta()`): a colour's stock is clamped at 0, so cancelling an open order for a colour that was short gives back the full quantity - set the colour stock before importing. The dialog's `fillForm()` now repeats the switches' defaults (it replaced them, so "Preview only" would have started off). Tests: `NuportOrderImportTest` (3 new for open orders). Tests: `NuportOrderImportTest` (2 changed, 1 new: callback finds an imported parcel).
## [2.92.0] - 2026-10-20

**Release type:** Minor Feature Update

### Added

- **Nuport import keeps the sales channel, the returned pieces and the courier parcels** (owner request 2026-10-20, so the reports can use them). Nuport's *Order Source* (WhatsApp, Website, Live chat, Messenger, Offline, Other) becomes the order's **Sales channel**: a column on the Orders list (hidden until you switch it on), a filter, and a badge on the order page. Nuport's *Cancelled/Returned/Damaged Qty* becomes each line's **Returned** pieces (shown on the order page when above 0; a cancelled order has none, because it never shipped). A parcel with a consignment number whose delivery is over (delivered, partly delivered, returned, cancelled) becomes a **courier booking**, so the courier performance report, the delivery history of the customer and the COD figures count it; a parcel still on the way is not recorded and keeps its consignment number in the order note. The booking uses the company's courier of the same name (Steadfast, Pathao, ...); a courier the ERP does not have is created switched off and without an API, so staff cannot book with it by mistake. The import preview and result list the channels, the bookings per courier, any courier to be created and the parcels left in the note. Importing the same file again never doubles a booking, and a newer file fills the channel and the booking of an order imported earlier. Nuport's *Entry By* is one name on every order and *Customer Tag* is Nuport's own label, so neither is kept.

### Fixed

- **The Edit order page keeps an order's own courier in the Courier box when that courier is switched off.** The box listed active couriers only, so saving an order whose courier had been switched off failed with "The selected courier is invalid"; the imported Nuport parcels use a courier that is created switched off, so they would have hit it.

### Technical Notes

- `OrderForm`'s `courier_provider_id` select adds `OR id = <the order's courier_provider_id>` to its active-couriers query (covered by `NuportOrderImportTest::test_an_imported_order_of_a_zero_stock_product_shows_normally_and_never_asks_for_stock`, whose imported order has a switched-off courier). Migration `2026_10_20_100000_add_sales_channel_to_orders_and_returned_quantity_to_order_items` (nullable `orders.sales_channel` with a `company_id` + `sales_channel` index; `order_items.returned_quantity` unsigned, default 0). **Deploy: run `php artisan migrate --force`.** `Order::SALES_CHANNELS` / `Order::salesChannelLabel()`; a channel Nuport adds later is stored as snake case and shown as a headline.
- `NuportOrderImportService`: `plan()` adds `sales_channel`, `booking` and `open_consignment`; `bookingPlan()` records only final courier statuses (`BOOKING_STATUSES`), so `couriers:sync-statuses` never polls an imported parcel and no stale-booking alert fires; `syncBooking()` saves quietly (no `CourierStatusLog`, no SMS, no customer risk review) and is idempotent per order + tracking id; `cod_amount` is Sales Amount + Delivery Fee for a COD order, else 0; `returned_at` is set only for Nuport's RETURNED sub-status (PENDING_RETURNED is still on its way back); delivery cost and margin stay empty (the file has none). `findProvider()` prefers an API driver named like the courier, then a slug or name match; `providerFor()` creates an inactive `manual` courier. Imported customers' bookings now count in `CustomerRiskService`'s delivered / returned / cancelled ratios. Tests: `NuportOrderImportTest` (3 new). 10 new interface strings in `lang/en.json` / `lang/bn.json`; `docs/codebase-map/sales-orders-couriers.md` updated.
## [2.91.0] - 2026-10-20

**Release type:** Minor Feature Update

### Added

- **One login for all businesses on the investor portal** (`17_IMS_IN_PORTAL_PLAN.md` §5, phase M). Someone with an active investor portal account under the **same phone number** in more than one business is treated as one person:
  - **Central sign-in address.** A Super Admin sets one address for every business in **Investments → Investor Portal Settings → One sign-in for all businesses** (for example `ims.yourdomain.com`; leave it blank to keep it off). There, investors sign in once with phone and password. Each business's own address keeps working as before. Password reset, SMS code sign-in, partner sign-up and invites stay on each business's own address.
  - **Switch business** button in the portal's top bar, listing the person's other businesses. On the central address, choosing one opens it in the same session. On a business's own address, it moves the person to the other business's address, already signed in.
  - **My businesses** page (under *More*, and in the switch menu). It has one card per business, showing capital at work, total invested, profit received, profit still to be paid and commission due, plus the totals. Businesses with different currencies are not added together.
  - Each business keeps its own account and figures, and a page never shows two businesses at once. A staff preview cannot switch. An account that is disabled, or has no password yet, is never offered.
- **Dark mode for the IMS** (owner request 2026-10-20). Both IMS panels — the investor portal (`/investor`) and the IMS office (`/office`) — now have **light and dark mode**. Light is the default. A round moon button in the top bar (and on the sign-in page) switches to dark, a sun button switches back, and the browser remembers the choice; Filament's own light/dark/system switch in the person menu works too. Green buttons, the balance card and the sign-in panel keep their green with white text; green text gets a lighter shade on dark cards.
- **New IMS sign-in page** following the owner's reference design (sign in, sign up, forgot password, invite and join pages). Wide screens: one round frame with a green gradient welcome panel on the left — logo, a small badge (*Investor portal* / *IMS office*), the big heading, one line of text and three numbered step cards (the first one white) — and the form on the right with soft grey fields, a full-width green button and, where there is a second way in (sign in with an SMS code), an "Or" line and an outlined button. Phones and tablets: the green panel sits on top with the steps in a row, and the form sheet overlaps it. The light/dark and English/বাংলা switches sit at the top of the form. No stock photos and no invented links; logo and name come from the company settings.

### Security

- Between two business addresses the switch uses a one-time token. It works for 60 seconds, only once, and only on the portal of the business it was made for. It is refused if either account was disabled after it was made.
- On the central address, a page opens only for the business chosen in that session, and only with that business's own account. When no business is chosen, nothing company-owned can be read.
- Every switch is recorded in the audit log (`investor_portal.business_switch_issued`, `investor_portal.business_switched`) and counts as a sign-in. Changing the central address is recorded too (`ims.central_domain_saved`).

### Technical Notes

- New `InvestorBusinessSwitchService`, `InvestorBusinessSwitchController`, middleware `ResolveCompanyFromCentralInvestorSession` (after `StartSession`, also persistent for Livewire), page `App\Filament\Investor\Pages\MyBusinesses`, `Company::imsCentralHost()` / `isImsCentralHost()` (`app_settings.ims_central_domain`). `RestrictPortalHosts`, `ResolveCompanyFromInvestorPortalDomain`, `Company::hostIsTakenByThePlatform()` and the investor `Login` page know the central host.
- Routes: `POST investor/switch-business/{company}` (signed in, throttled) and `GET investor/business-entry/{token}` (throttled). Top-bar view `filament/investor/business-switch` (inside `ModuleGuard::run`).
- The central address may be a new name under a company's own domain (for example `ims.<company domain>`); `Company::centralHostIsTaken()` refuses only a host that already answers (company domain, any portal host, IMS or Next.js domain, a reseller's own domain or store subdomain). Once set, its label is reserved for that company's reseller store subdomains (`Company::reservedResellerSubdomains()`), and it never resolves as a reseller store.
- No migration. 14 new interface strings in `lang/en.json` / `lang/bn.json`. Tests: `InvestorBusinessSwitchTest` (16).
- **Deploy:** point the central domain to the server (Cloudflare + Coolify), then fill it in on the settings page.
- Both IMS panel providers: `->darkMode()` + `->defaultThemeMode(ThemeMode::Light)`. New views `filament/ims/theme-toggle` (top bar, `GLOBAL_SEARCH_AFTER`; dispatches Filament's `theme-changed`) and `filament/ims/auth-tools` (theme + language switch, `SIMPLE_PAGE_START`); `filament/ims/auth-hero` rewritten with the steps.
- `resources/css/filament/ims/theme.css`: every hard-coded colour is now a token (`--ims-raised`, `--ims-nav-*`, `--ims-on-primary-*`, `--ims-primary-text` / `-ink`, soft warning/danger/info tones, `--ims-field`, `--ims-auth-*`); `:root.dark` redefines them. Older portal views with grey utility classes and no `dark:` variant are covered by a few dark overrides.
- 8 new interface strings in `lang/en.json` / `lang/bn.json`. Test: `tests/Feature/Ims/ThemeAndSignInTest.php` (3). No migration.
## [2.90.1] - 2026-10-20

**Release type:** Maintenance Update

### Technical Notes

- **Code map for AI agents** (owner request 2026-10-20): new `CODEBASE_MAP.md` (index) and one file per module in `docs/codebase-map/` (core platform, catalog/inventory, sales/orders/couriers, accounts/finance, Blade storefront, API/integrations, reseller, CRM/messaging, investments/IMS, AI/marketing tools, other apps) with where-to-start files, file tables, routes, main tables and rules. Agents read the index and one module file instead of scanning the repository. Drafted by another agent, then checked against the code: every path exists, every route matches `route:list`, every column appears in a migration; invented migration names, routes and behaviour were removed or corrected. `CLAUDE.md` and `AGENTS.md` now say to read the map first and to update it in the same commit as a structural change.
## [2.90.0] - 2026-10-20

**Release type:** Minor Feature Update

### Added

- **Exchange rates, and project costs in US dollars or RMB.** Owner decision 2026-10-19: the investor portal becomes the IMS (no separate stack), and the IMS keeps daily USD and RMB rates. **Investments → Exchange Rates** lists the rate staff type for each currency and day (US Dollar, RMB / Chinese Yuan; BDT for one unit); one rate per currency per day, and nothing is fetched from outside. A project cost (**Cost Items** on the project) now has a **Currency**: BDT as before, or USD / RMB with the **Cost date**, the **original amount** and the **rate**. The form fills the rate from the newest one on file on or before the cost date (you can change it, and it says when none is on file), and shows the BDT amount as you type. The BDT amount is always worked out by the system (original amount × rate, to the paisa), so the settlement, the landed cost and every report read the same BDT figure as before; the cost list shows the original amount and rate beside it. Costs entered before this change are BDT and are unchanged. Adding or changing a rate needs the *Manage investments* right; viewing needs *View investments*. When a company runs IMS on its own domain the page is in the IMS office under **Tools → Exchange Rates**, and the old ERP address `/admin/investments/exchange-rates` leads there.
- **Plan for the IMS inside the investor portal.** New `17_IMS_IN_PORTAL_PLAN.md`: what each earlier IMS setup became now that the investor portal is the IMS (no separate stack), what IMS v2 already covers (profit proposals from the cycle's data) and the one piece it does not: one sign-in across several businesses, with the owner questions it needs. Not built yet.

### Technical Notes

- Exchange rates: migrations `2026_10_19_100000_create_exchange_rates_table` (unique `company_id` + `currency` + `rate_date`, named index) and `2026_10_19_100100_add_foreign_currency_to_project_cost_items` (nullable `currency`, `original_amount`, `exchange_rate`, `cost_date`; guarded by `hasColumn`). New `ExchangeRate` model (company-scoped, in `MultiCompanyIsolationTest`), `ExchangeRate::rateOn()`, `ExchangeRateResource` in the Investments cluster. `ProjectCostItem` converts in its `saving` hook and rejects a foreign cost without both figures. Tests: `ExchangeRateTest` (13), mutation-checked (form amount kept instead of recomputed; rate from the day after). **Deploy: run `php artisan migrate --force`.** 19 new interface strings in `lang/en.json` / `lang/bn.json` (Bangla to review). The resource is registered in `ImsOfficePanelProvider` and listed in `OfficeNavigation` (Tools); `tests/Feature/Ims/ExchangeRatesInOfficeTest` (2) pins the office page, its menu entry and the redirect from the ERP address.
## [2.89.0] - 2026-10-20

**Release type:** Minor Feature Update

### Added

- **IMS v2: the investor system on its own main domain** (owner, 2026-10-19; plan in `IMS-V2/`). A Super Admin sets the domain and switches it on in **Investments → Investor Portal Settings → IMS v2 on its own domain**. The domain itself opens the investor portal (new green look, Bangla + English, phone first) and `/office` opens the new **IMS office** for staff, with its own sign-in. `www.` works too; nothing of IMS opens on any other address, and the old investor-portal address sends people to the new domain.
- **IMS office** with a plain-words menu: Home (**What to do today**, four big numbers, groups now), Investors, **KYC**, **Invites**, Investor Drafts, Channel Partners, Investor Groups, Projects, Investments, **Money to confirm**, **Profit proposals**, Settlements, Payout Batches, **Capital returns**, Investor Requests, **Support**, **Feed**, **Project updates**, **Project reports**, **Statement matching**, Agreement Templates and **IMS settings**. Each entry shows only to staff allowed to use it.
- **Joining with an invite:** the office invites a phone or e-mail; the person gets a link (14 days), proves it with a 6-digit code (10 minutes, 5 tries, one send a minute), gives their details, one to five **nominees** (shares adding up to 100%) and a password. Off until switched on in IMS settings.
- **KYC:** NID front, back and a selfie taken with the phone camera; read on our own server when Tesseract is installed (otherwise checked by eye); a checklist (NID format, age 18+, all pictures, NID not on another investor, card matches); approve or turn down with a reason the investor sees. **While IMS is on, a new investor's money becomes active only with a verified KYC**; money already at work is not touched.
- **"Waiting for the money":** deposits and investments can wait until someone with the new confirm permission says the money arrived; only then is the receiving voucher booked.
- **Optional tenor** on a deposit or investment, with its maturity date, a reminder before it (7 days unless changed) and **capital paid back** in parts or in full, never more than what is left after losses; a return is voided, never deleted.
- **Project transparency for investors:** a Projects page, and per project its news (with photos and a video link), a timeline, shipments (no supplier, container or tracking number), the totals worked out from the **purchases linked to the project** (costs in four parts, stock, units and money sold, net profit, the investors' pool and the investor's own share — marked "estimate" until settled) and published **reports** (frozen, PDF).
- **Profit proposals:** when a cycle ends the system works out its profit and asks the office to accept it, enter the profit by hand, or dismiss it. Nothing is shared out by the proposal itself.
- **Notifications:** a bell and list in the portal for every piece of news; SMS (the owner's own wording, SMS Templates → IMS news) and e-mail (company SMTP) per event as chosen in IMS settings. **Feed** with likes and comments (others see a comment without a name), **support conversations**, **monthly statement PDF** (portal download, and by e-mail on the 1st), the office's **morning to-do e-mail**, and **late-shipment alerts**.
- **Statement matching:** read a bKash / Nagad / Rocket / bank statement (CSV or Excel); a money-in line is matched only to waiting money of exactly the same amount; the accountant marks the match and someone with the confirm permission makes it active.
- **Numbers in IMS** use lakh grouping (৳ 5,20,000) and Bangla digits in Bangla.
- **Password reset by e-mail on the investor portal.** "Forgot password?" now asks how to send the code — **by SMS or by e-mail** — offering only the ways the company has set up. By e-mail the 6-digit code goes to the address on the investor's record and works for 10 minutes (SMS: 1 minute). The reply is the same whether or not the address belongs to an account, and an address shared by two investors gets no code.
- **Go-live checklist** for IMS v2 (`IMS-V2/GO-LIVE.md`, in Bangla): code and backup, server checks, domain (Cloudflare, Coolify), settings to fill in, switching IMS on, first-day checks and clean-up.
- **Server brief for the owner's VPS AI agent** (`IMS-V2/VPS-AGENT-BRIEF.md`, owner request 2026-10-20): what the agent does to put IMS v2 on its own domain (database backup before the deploy, Cloudflare DNS, adding the domain to the ERP app in Coolify without touching other domains, checks for migrations, OCR, scheduler, queue, `TRUSTED_PROXIES` / `SESSION_DOMAIN`, outside checks after the switch, undo) and what it must never do (push or merge, run destructive database commands, switch IMS on itself).

### Security

- **The old IMS data-sync API is turned off** (`/api/v1/sync/*` and `/api/v1/sync/investor/*` now answer 404). The old IMS is gone and IMS v2 runs inside this app, so nothing read it any more — and the investor feed carried NID, phone and bank details. No new "Data sync" or "Investor sync" key can be made; keys made before show as "turned off" in Settings → Integrations → Website API and can be revoked there.

### Changed

- **Loss rule (owner decision 2026-10-19):** when investors bear a loss they bear **the loss × the project's investor %**, shared by capital and **taken from their capital** — never more than one put in; the company bears the rest. Example: 35%, loss ৳1,00,000 → investors −৳35,000, company −৳65,000. This now also works on **group projects** (it was blocked), and the smaller capital carries on into the group's next project once the loss is confirmed. "Company bears the loss" is unchanged.
- **Void a settlement:** Super Admin only, with a reason of at least 10 letters.
- **Role defaults (IMS-V2/ROLES.md):** a Manager now also settles, manages portal access, approves payout batches, reviews KYC, confirms money, decides proposals, posts updates and the feed and answers support; an Accountant now also makes payout batches, pays back capital and matches statements. Super Admin keeps everything.
- **Investor portal:** new green IMS look; phones get five tabs (Home · Investments · Profit · Feed · Profile) and the Profile page lists every other page. Portal passwords now need at least 10 characters with letters and numbers.
- When IMS is on, the ERP admin's Investments menu becomes one link to the IMS office and old `/admin/investments/...` links open the same page there (Investor Portal Settings stays in the ERP).
- **New IMS look, redone to match the owner's reference pictures** (owner feedback 2026-10-19: the first version still looked like the ERP). Both the IMS office and the investor portal now have a **round white top bar with the menu as pills** (the open page in white, the rest under drop-downs such as **More**), round search / bell / profile buttons, and a **menu rail on the left** with the most used pages (the open one in a green circle, **Sign out** at the bottom) that **opens with the page names when the mouse is over it**; **Keep the menu open** pins it open (remembered in the browser) and the page moves aside. The **office home** is a new card dashboard: "Welcome back, name" with today's date and **Add investor**, the green **capital at work** card, **came in this month** compared with last month, a **profit paid per month** bar chart (6 or 12 months, tap a bar to read it), **What to do today**, **Latest money in**, **Payouts still to send** with the people whose KYC is waiting, and the open groups as cards. The **portal home** opens with "Good morning — Welcome back, name", the green balance card, round quick buttons (Add money, Statement, Projects, Support), the monthly profit chart, the next payout, money by group, the projects and **recent transactions** with an icon per kind of money. The **sign-in pages** (portal and office, also invite and password pages) have a green welcome panel beside the form, and on phones a green top block with the form below. On small phones the language switch moves to Profile (portal) or the menu (office). The "what to do today" lines are now in Bangla too.
- **IMS navigation hover-expand & docked panel (owner feedback 2026-10-19):** The left menu bar now dynamically expands on mouseover (`mouseenter`) into a comprehensive view showing full names alongside icons, and collapses back (`mouseleave`, with smooth debounce) to the compact icon rail. Both hover-expansion and pinned mode smoothly adjust the page container padding (`padding-inline-start: 18.25rem`) alongside the menu with zero overlap on page content.

### Technical Notes

- Migrations `2026_10_19_100000` … `100400`: `companies.ims_enabled` / `ims_domain`; KYC columns on `investors`; `investor_nominees` (the old single nominee is copied in at 100%, nothing deleted), `investor_kyc_documents`, `investor_invites`, `investor_otp_codes`; tenor / maturity / confirmation columns on `investments` and `investor_group_members`; `settlement_payouts.capital_loss_amount`; `project_settlements.investor_loss_percent` / `locked_at`; `investment_project_purchases`, `capital_returns`, `profit_proposals`, `project_updates`, `project_update_photos`, `project_milestones`, `project_reports`, `feed_*`, `support_threads` / `support_messages`, `statement_dispatches`, `bank_statement_imports` / `bank_statement_lines`; `shipments.ims_delay_alerted_at`. All long indexes named. **Deploy: `php artisan migrate --force`.**
- New code in `app/Ims/` (Capital, Kyc, Onboarding, Profit, Transparency, Reports, Feed, Support, Notifications, Reconciliation, Todo, Settings, Files), `app/Filament/Ims/Office|Portal`, `ImsOfficePanelProvider` (panel id `office`), `ResolveCompanyFromImsDomain`, `RedirectInvestmentsToImsOffice`, `resources/css/filament/ims/theme.css` (both IMS panels). The ERP's investor resources are registered in the office panel unchanged; their file links go through `App\Support\ImsPanelLinks`.
- KYC OCR: added `tesseract-ocr` and `tesseract-ocr-ben` packages to `nixpacks.toml` setup phase (`aptPkgs`), with Coolify container deployment documentation in `docs/deployment.md` and graceful manual fallback when unavailable.
- Responsive styling & accessibility: `.fi-investor-language a` and `.fi-ims-language a` increased to 44px min-height in `resources/css/filament/ims/theme.css` to meet mobile touch-target guidelines; added `overflow-wrap: anywhere` and `overflow-x: clip` to prevent horizontal scrolling on 360px viewports; added link wrapping in feed and support threads.
- IMS look redo: both IMS panels use Filament `topNavigation()` (portal menu built by `App\Filament\Ims\Portal\PortalNavigation`, office by `OfficeNavigation`, each with a `rail()` for `resources/views/filament/ims/icon-rail.blade.php` on `BODY_START`); the portal's old dark sidebar, `topbar(false)` and sidebar user menu are gone. Office home is a custom view (`filament/ims/office/pages/home.blade.php`) placing six guarded widgets in a CSS grid: `OfficeMoneyCards`, `OfficeProfitChart`, `OwnerTodoList`, `LatestMoneyIn`, `OfficePayoutsDue`, `RunningGroups` (now cards); `OfficeStats` and the portal's `ProfitTrend` chart widget were removed. New `OfficeFigures::profitPaidByMonth()` / `latestMoneyIn()`. Shared Blade components `x-ims.money` (lakh + light paisa), `x-ims.bar-chart`, `x-ims.avatar` (initials, no photos) and `filament/ims/welcome-header.blade.php` (via `getHeader()`); sign-in panel `filament/ims/auth-hero.blade.php` on `SIMPLE_LAYOUT_START`. Primary buttons are forced to the deep IMS green with white text (Filament had picked a light shade). Tests: new `tests/Feature/Ims/OfficeHomeTest.php`; `InvestorPortalTest` (menu order) and `InvestorPortalGroupViewTest` (heading) updated. 46 more strings in `lang/en.json` / `lang/bn.json`, including the 6 office to-do lines that were missing.
- Sync API removal: route groups `v1/sync` and `v1/sync/investor`, `SyncController`, `InvestorSyncController`, `ImsSyncFeed`, `InvestorSyncFeed`, the `sync-api` rate limiter and `ImsSyncApiTest` / `InvestorSyncApiTest` deleted; `CompanyApiKey::CREATABLE_SCOPES` (website only) used by `WebsiteApiKeysTable`; the scope constants stay for old rows. `docs/api/ims-sync-api.md` marked as turned off. New `tests/Feature/SyncApiTurnedOffTest.php`.
- E-mail reset: `InvestorPortalAccountService::findByEmail()` (one matching investor only), `emailAvailable()`, `sendResetCodeByEmail()` (`EMAIL_CODE_VALID_MINUTES = 10`, same `otp_*` columns and 5-try rule as SMS, sent with `ImsMessage` through the company SMTP); `Investor\Pages\Auth\ForgotPassword` has a `channel` choice (`sentBy` / `sentTo` replace `phone`). New `tests/Feature/Ims/EmailPasswordResetTest.php`. 11 new strings in both language files.
- 14 new permissions (`ims.*`). PDFs use the self-hosted Hind Siliguri font (dompdf caches it in `storage/fonts`, now git-ignored); complex Bangla letters may not join perfectly in PDFs (a dompdf limit).
- Tests: new `tests/Feature/Ims/*` (domain routing, onboarding, KYC, transparency + privacy, capital, proposals and jobs, communication and tools). Updated to the owner's new rules: `InvestmentSettlementTest` (loss × %), `InvestorGroupExitTest` (group loss now allowed), `PayoutBatchTest` and `InvestorPortalTest` (new role defaults). 513 new strings in `lang/en.json` / `lang/bn.json` with Bangla naturalization verified via `TranslationParityTest`.
## [2.88.1] - 2026-10-19

**Release type:** Patch/Fix Update

### Fixed

- **Marketplace Pro: footer and top bar unreadable when the secondary colour is light.** The top bar and footer carry light text but took their background from the admin's secondary colour, so a white or pale secondary turned them light grey and the text vanished. They now keep a dark surface (the secondary colour when it is dark, otherwise the default navy). The header still follows the secondary colour, with text colour chosen for contrast.

- **Marketplace Pro: hero banner no longer cut off on desktop.** The banner was locked to a 4:1 frame and cropped any slide that was not exactly 4:1. It now takes the height of the uploaded image (up to 30 rem) and shows the whole picture. Mobile is unchanged.

### Technical Notes

- New palette key `secondary_deep` (`StorefrontSetting::themePalette()`), printed as `--storefront-secondary-deep`; no migration. Test: `StorefrontThemeTest::test_light_secondary_colour_keeps_the_footer_surface_dark`.
## [2.88.0] - 2026-10-19

**Release type:** Minor Feature Update

### Added

- **AI or team: every chat now shows who answers it, and goes back to the AI on its own** (16_AI_INBOX_CONTROL_PLANE_PLAN.md, phase 1, modelled on AiPraktor). The Inbox's "AI assistant" switch is replaced by an **AI or team** panel: **Take over** (for the company's takeover time), **Keep with the team for** 30 min / 1 hour / 6 hours / No time limit, **Back to AI**, and **Claim** (assign to me). It shows when the chat returns to the AI and, when the AI handed it over, why.
- **The AI hands over with a reason.** When the AI gives a chat to the team it records one reason from a fixed list (asked for a discount, complaint, refund, asked for a person, outside what the AI knows, angry customer, ready to buy, AI could not reply, other). The chat goes back to the AI when the takeover time is up, unless someone keeps it with no time limit.
- **Type "." from the page inbox** (Messenger / Business Suite) to take a chat for the team; another "." gives it back to the AI.
- **CRM → AI / Team Activity**: every switch between the AI and the team, from every source (Dashboard, AI, Auto-revert, Answered, Page inbox, Dot command), with filters and CSV export.
- **New Auto Messaging settings** (Integrations → Auto Messaging): team time before a chat returns to the AI (default 360 minutes, like AiPraktor), wait before answering (0–15 seconds, default 3, unchanged), tell the customer when a person takes over (off by default), neatly arranged replies (off by default), and how the AI addresses the customer in Bangla (no title / Sir–Madam / Bhaiya–Apu, default no title).
- **Neatly arranged replies (opt-in).** Product facts go on their own lines with small labels: 💰 price, 📏 sizes/colours in stock (never a stock count), ✅/❌ in stock, 🚚 delivery charge per zone, 🛒 order link, followed by "which one would you like?" for products with options. With an address style chosen, a Bangla reply can open with "জ্বি স্যার," / "জ্বি আপু," when the customer's words or name make it clear.
- **CRM → Needs attention** (plan 16, phase 2): every chat the AI handed to the team, with its reason, how long it waited for a person's first reply, who answered, and how it ended — answered, given back to the AI, never answered (went back to the AI on its own with no reply), or closed without a reply. Filters for open / done, reason, outcome, late only and "given to me"; **Claim** and **Done** buttons; the menu shows the number still open, red when one is late.
- **Reply target for handed-over chats** (Integrations → Auto Messaging, minutes, 0–1440): a handed-over chat with no reply from a person within this time is marked late. Default 30 minutes, around the clock (owner decision 2026-10-19); 0 = no target.
- **CRM → AI Inbox Report** (plan 16, phase 3), for today / 7 / 30 / 90 days:
  - chats with customer messages, and the share the AI handled alone;
  - AI replies and team replies;
  - AI handovers and team takeovers, and chats that went back to the AI on their own;
  - average and median first reply after a handover;
  - late share, never answered, and waiting now (with the longest wait);
  - why the AI handed over;
  - a by-channel table.
- **CRM → Agent Desk** (plan 16, phase 4):
  - three boxes for the sales agent's written guidance: about the business, voice, and conversation guidelines (the last two are the existing "Brand voice" and "Sales agent conversation guidelines" settings);
  - **Save draft**, **Publish** (with an optional note) and **Discard draft**;
  - a **test chat** where the owner writes as a customer and sees the AI's reply, or that it would hand the chat to the team and why, plus what it looked up. It reads the real catalog and FAQ, sends nothing to anyone and creates no order, link or lead; it can try the unpublished text in the editor;
  - a **Versions** list (including changes saved on Integrations) with **Open in editor** and **Make live again**.
  - Only people who can manage settings can edit, publish or test; everyone with CRM view access can read it.
- **CRM → Unanswered questions**: chats the AI handed to the team in the last 30 days because it had no verified answer, with what the customer asked and an **Add FAQ answer** button, so the AI can answer the same question next time.
- **CRM → Knowledge base** (plan 16, phase 5): the company's own documents for the sales agent (fees, rules, packages, returns, policies).
  - Write plain text or Markdown, or **Import file** (.md / .txt). A heading starts a new topic; the AI finds a short passage (up to about 700 characters) and it is sent to the customer exactly as written, the same way FAQ answers are.
  - **Test a question** shows which passages the AI would find for a customer question. Nothing is sent and the AI is not called.
  - Only active documents are used. Only people who can reply and manage the CRM can change them.
  - **Unanswered questions** has a new **Add to knowledge base** button next to **Add FAQ answer**.
- **CRM → Setup checklist** (plan 16, phase 8, checklist part): eight steps that make the AI inbox ready for real customers, each with a link to where it is done: connect a chat channel, add an AI key and turn the AI on, describe the business, products with photos, delivery charges, saved answers or documents, phone alerts, and a test chat run. Steps turn done by themselves from the company's data; a progress bar shows how many are done.
- **More messages go to the team, each with its own reason** (plan 16, owner answers of 2026-10-19). A customer who writes that they paid (bKash/Nagad, TrxID, "টাকা পাঠিয়েছি"), asks for a phone call, or asks for a booking is handed to the team at once, and the Needs attention list shows the reason: **Payment proof sent**, **Asked for a call**, **Asked for a booking**. A payment screenshot is recognised when the customer adds a caption, or when the separate image model describes the photo. More complaint wording now counts as a complaint: damaged, wrong item, fraud, scam, প্রতারণা, ধোঁকা, ভুল পণ্য, ভাঙা, ড্যামেজ.
- **Website leads on Needs attention.** A new lead that comes in from the website is a line on the list (reason **New website lead**, with its interest as the note) and is answered as soon as someone moves the lead past "New". The list has an **Open lead** button.
- **Sales follow-up reminders follow a schedule** (plan 16, owner answer O4). A customer who was sent a checkout link or a quotation and has not ordered gets a reminder **1, 6, 24 and 48 hours after their last message, then once a week for 4 weeks**. A new message from the customer starts the count again. Nothing is sent from 22:00 to 08:00 (company time). The reminders stop when the customer orders, opts out, or a person writes to them; a newer offer replaces the older schedule.
  - WhatsApp uses the approved template at every step. Messenger and Instagram allow a normal message only within 24 hours of the customer's last message: inside that time the company's **reminder text** goes out (Integrations → Auto Messaging; `{link}` becomes the order link); after it, or with no text saved, the reminder is a line on Needs attention for the team.
  - The hours, the number of weekly reminders, the quiet hours and the Messenger text are settings on Integrations → Auto Messaging. **Sales Follow-ups** shows how many reminders each customer has had.
- **Messenger broadcast** (plan 16, owner answer O5). A broadcast can go to a Facebook Page's Messenger chats. The audience is the chats that wrote to the Page in the last 23 hours (Messenger allows nothing else), and every chat is checked again at the moment its message is sent. **Send test** sends the exact message to your own chat first; the broadcast cannot be sent until a test of its current text succeeded, and changing the text asks for a new test. People with CRM manage permission can send.
- **Courier → Scan parcels** (plan 16, phase 7): scan or type invoice numbers (USB scanner, or the phone camera reading the barcode printed on the invoice), then move the whole list to one stage, for example Shipping. Every order goes through the order workflow, so the allowed steps and the status log still apply; an order that cannot make the move is skipped with the reason.
- **Courier → Handover sheet**: one courier, one day: how many parcels, the cash to collect, and a list with invoice, customer, phone, address, cash and the consignment/tracking number. **Print sheet** opens a clean page for the rider.
- **CRM → To chase**: people who wanted to buy and did not: carts left behind, checkouts that stopped at the advance payment, and leads with no order, for the last 7 / 14 / 30 days. Each person appears once, with call and WhatsApp buttons and **Mark contacted**; someone who ordered since is left out, and someone marked contacted comes back only if they show interest again. Offer taps are not on the list: nothing records who tapped an offer.
- **Practice mode** (plan 16, §4.9; owner request 2026-10-19, which replaces the earlier answer "no practice mode"). A company that turns the AI on from Integrations starts in practice mode: the AI answers only the chats that staff marked as test chats. Real customers get no AI reply and no reminder until someone goes live.
  - Inbox: **Use as test chat** / **Remove test mark** (people who can manage the CRM), shown while the AI is not live.
  - Setup checklist: a ninth step **Go live**, and **Go live** / **Back to practice mode** buttons (people who manage settings; the AI must be on). Integrations → Auto Messaging has the same switch, **AI answers real customers (live)**.
  - A company whose AI was already on keeps answering everyone: nothing changes for it until it chooses practice mode.
  - Handovers to the team, the Agent Desk test chat and broadcasts do not depend on it.
- **"Seen" and "typing..." while the AI answers** (Integrations → Auto Messaging, on by default). Just before the AI works on an answer, a Messenger customer sees the message marked as seen and "typing...", and a WhatsApp customer sees the message read and "typing...". Nothing is shown for an instant saved answer or when the AI hands the chat to the team (Messenger gets "typing" taken away). A failed call is ignored: it never delays or stops the answer.
- **Messenger product cards** (Integrations → Auto Messaging, off by default). After an answer that names products, a Messenger customer also gets a swipeable row of up to 10 cards: photo, price and stock, and a button. The button opens the order link when the answer has one, otherwise the product page on the company's storefront domain. A product without a photo gets no card. The chat history shows the row as "🛍 product names". A failed row is only logged; the written answer has already been delivered.

### Changed

- **A message with "ফেরত", "রিফান্ড" or "refund" now looks in the saved answers first.** If an FAQ or a knowledge passage covers it, the AI answers with that; only when nothing does the chat goes to the team. "complaint", "human", "agent" and the discount words still go to the team at once.
- **The reply target for handed-over chats is 30 minutes by default** (was none). A company that already saved its own number keeps it.
- **Sales follow-up settings:** "Sales follow-up delay (hours)" is replaced by the reminder times, weekly reminders, quiet hours and Messenger text. A checkout link that expired after 7 days no longer ends the reminders; only a finished checkout does. "One follow-up per conversation per day" is gone, because the schedule sends more than one a day.
- **The Setup checklist has nine steps:** the new last step is **Go live** (done when the AI is on and live).
- **A staff reply now keeps the chat with the team for the company's takeover time (default 6 hours) instead of a fixed 24 hours**, and a reply typed in the page's own inbox does the same. A chat the AI handed over now returns to the AI after that time instead of staying off until someone switches it back.

### Technical Notes

- New `conversations` columns `automation_mode` (`ai` | `human`), `handover_reason`, `mode_changed_at`, `mode_changed_by`; `human_handled_until` keeps its meaning and is the return-to-AI time (NULL = no time limit). Migration backfills `human` for every chat with AI switched off or held until a future time. New company-owned table `conversation_mode_events` (`ConversationModeEvent`, in `MultiCompanyIsolationTest`).
- `App\Services\Crm\ConversationModeService` is the only writer of the mode (`toHuman()`, `toAi()`, `staffAnswered()`, `revertDue()`); it keeps `ai_enabled` in step (false while the team has the chat) so existing checks keep working, and writes one event per switch. `AiReplyService::escalate()` takes a reason code and switches through it; `escalate_to_human` gained a required `reason_code` enum.
- New command `crm:revert-conversations`, scheduled every minute, loops per company.
- `AiAutoReplyJob::dispatchAfterWait()` replaces the fixed 3-second delay with `reply_delay_seconds`.
- `SalesReplyRenderer::render()` gained a `$style` argument (`rich`, `address_style`) and `submit_reply` an optional `salutation` (sir | maam | none); `handoverNotice()` renders the handover message in the chat's language. Defaults leave every existing reply byte-for-byte unchanged.
- Tests: `ConversationModeTest` (14 tests); `InboxPageTest` updated for the new panel keys.
- New company-owned table `attention_items` (`AttentionItem`, in `MultiCompanyIsolationTest`): `kind`, `conversation_id`, `reason`, `note`, `opened_at`, `due_at`, `first_response_at`/`_by`, `resolved_at`/`_by`, `outcome`. `App\Services\Crm\AttentionQueueService` keeps at most one open item per chat; `ConversationModeService` opens it when the AI hands over (source `ai`), records the first staff reply in `staffAnswered()`, and closes it on every switch back to the AI. A takeover from the dashboard, a staff reply or "." opens nothing. The push alert from `AiReplyService::escalate()` is unchanged. New setting `response_target_minutes`. Tests: `AttentionQueueTest` (7 tests).
- `App\Services\Crm\AiInboxReportService` computes the report on request from `conversation_messages`, `conversation_mode_events` and `attention_items` in the current company's scope; nothing new is stored. Page `AiInboxReport`. Tests: `AiInboxReportTest` (3 tests).
- New company-owned table `ai_instruction_versions` (`AiInstructionVersion`, in `MultiCompanyIsolationTest`): `blocks` (about, voice, guidelines), `status` (draft | published | superseded), `source` (agent_desk | settings | restore), `change_note`, `published_at`/`_by`. The live text stays in the company's AI settings (`business_about`, `brand_voice`, `sales_guidelines`), so the agent's prompt source is unchanged: `AiInstructionService::publishDraft()` / `restore()` write the settings, and `AiSettingsService::save()` records a version whenever the Integrations page changes those fields. New `business_about` setting, added to the prompt as background that is explicitly not a source of prices, stock or promises.
- `AiReplyService::dryRun()` runs the real agent for the Agent Desk test chat: read-only lookups are real, `create_order_link` / `update_qualification` / `create_quotation` answer "test mode", `escalate()` and `sendAiReply()` return at once while the flag is on, and `ConversationMessengerService::send()` now refuses an unsaved conversation. Test runs are logged as `crm_ai_runs.is_test` (with `conversation_id` now nullable): they count toward the daily budget but not the daily run limit and are left out of Sales Automation and its metrics.
- Tests: `AgentDeskTest` (12 tests).
- New company-owned table `knowledge_documents` (`KnowledgeDocument`, in `MultiCompanyIsolationTest`): `title`, `content` (max 20,000 characters), `is_active`, `source_file`. Saving or deleting a document clears the per-company passage cache (`crm:knowledge:{company}`, 5 minutes).
- `KnowledgeBaseService` splits a document at its headings into passages of at most 700 characters and searches them in PHP, not with MySQL FULLTEXT: InnoDB's tokenizer breaks Bangla words at vowel signs. A word matches when it, the word without a trailing `s`, or (5+ characters) the word without its last two characters is in the passage; a hit in the title or heading counts three times; at least half of the question's words must match.
- New agent tool `lookup_knowledge(query)` is offered only while the company has an active document. Its reply option is `knowledge:<document id>.<position>`; `SalesReplyRenderer` sends the stored passage and rejects a passage the agent did not look up. Amounts written in a looked-up passage count as grounded (`AiReplyService::amountsIn()` is the shared amount reader).
- Tests: `KnowledgeBaseTest` (10 tests).
- `AiSetupChecklistService::steps()` works the checklist out from the data (channel, AI settings, `business_about`, products with a photo, the default courier's `delivery_fees`, FAQs and knowledge documents, the signed-in user's `PushDevice`, a `crm_ai_runs.is_test` row). Nothing is stored. Tests: `AiSetupChecklistTest` (6 tests).
- `AiReplyService::keywordHandover()` is the one place that decides whether a message goes to the team without asking the model (also used by the Agent Desk test chat). Word lists are constants: `HANDOFF_KEYWORDS` (= `COMPLAINT_KEYWORDS` + `HUMAN_KEYWORDS`), `RETURN_KEYWORDS` (saved answers first, via `hasSavedAnswer()`), `PAYMENT_KEYWORDS`, `CALL_KEYWORDS`, `BOOKING_KEYWORDS`. New handover reasons `payment_proof`, `call_requested`, `booking_requested`, `website_lead`, `follow_up_due`; `AttentionItem::KIND_BY_REASON` maps them to the item kinds `payment_proof`, `call_request`, `booking_request`, `website_lead` (anything else stays `handover`). `response_target_minutes` default is 30.
- Migration `2026_10_19_000100`: `attention_items.lead_id` (nullable, cascade). `AttentionQueueService::openedForLead()` / `leadWorked()`; `LeadController::store()` opens the item and `Lead::booted()` closes it when the status leaves `new`. Tests: `AiHandoverRulesTest` (11).
- Migration `2026_10_19_000200`: `crm_sales_follow_ups.step` and `base_at`. One row now carries a whole schedule: `step` = reminders dealt with, `base_at` = the customer message the hours are counted from, `due_at` = the next one. `SalesFollowUpService::offsets()` builds the list (`follow_up_schedule_hours` + `follow_up_weekly_count` weeks of 168 hours after the last daily step); new settings `follow_up_schedule_hours`, `follow_up_weekly_count`, `follow_up_quiet_start_hour`, `follow_up_quiet_end_hour`, `follow_up_message` (`follow_up_delay_hours` is no longer read). Statuses: `pending` between steps, `sent` or `completed` at the end. Tests: `SalesFollowUpScheduleTest` (11); `CrmSalesAutomationTest` follow-up tests updated.
- Migration `2026_10_19_000300`: `broadcasts.messenger_channel_id`, `messenger_text`, `tested_hash`, `tested_at`; `broadcast_recipients.phone` is nullable (a Messenger recipient is a conversation: `recipient_type` = `conversation`). `Broadcast::needsTest()` compares `tested_hash` with `contentHash()` (Page + text); `BroadcastService::queue()` refuses an untested Messenger broadcast; `sendTest()` / `messengerBlocker()` re-check the 23-hour window per recipient. Tests: `MessengerBroadcastTest` (9).
- Migration `2026_10_19_000400`: company-owned `chase_marks` (`ChaseMark`, in `MultiCompanyIsolationTest`): normalised phone + who/when. `ToChaseService` works the list out from `StorefrontCartRecord`, pending `StorefrontPayment` rows that still hold `checkout_data` (the only place an unpaid checkout keeps a phone), and `Lead`; `StorefrontCheckoutAttempt` stores only masked contacts, so it cannot be chased. Tests: `ToChaseListTest` (7).
- `AiSettingsService`: new `ai_live` (deliberately not in `DEFAULTS`: `all()` returns the saved value, or the `enabled` value when nothing was saved, so companies already running stay live; `save()` ties an unsaved value to `enabled`, and only the Integrations form saves it explicitly), `typing_indicator_enabled` (default true) and `product_cards_enabled` (default false); `setLive()` and `isLive()`.
- Migration `2026_10_20_000100`: `conversations.is_staff_test`. `AiReplyService::maybeReply()` writes a `skipped` run with the practice-mode reason for a non-test chat while the AI is not live; `SalesFollowUpService::process()` leaves the reminders `pending` meanwhile.
- `MetaGraphService`: `sendMessengerAction()` (`mark_seen`, `typing_on`, `typing_off`), `sendWhatsAppTyping()` (`status: read` + `typing_indicator`), `sendMessengerCards()` (generic template) and `publicMediaUrl()`. `ConversationPresenceService` is called by `AiReplyService::runAgentLoop()` before each model round (typing renewed at most every 12 seconds; seen once per message in `raw_payload._local.seen_at`) and never records a channel fault.
- `ProductCardService` builds the cards from the reply keys the renderer already accepted (price and stock read from the records); `ConversationMessengerService::sendCards()` stores the row as an outgoing message with `ai_meta.kind = product_cards` and deletes it again if Meta refuses it, so there is no failed bubble to retry. `consecutiveAiReplies()` and the AI Inbox Report skip such messages.
- Tests: `PracticeModeTest` (12) and `AiPresenceAndCardsTest` (13); `AiSetupChecklistTest` now expects nine steps.
- `NeedsAttention` writes its sidebar badge as `navigationBadge()` with `HasGuardedNavigationBadge`, the rule `ModuleGuardTest` enforces for every badge.
- New pages `ScanParcels` (Courier cluster, `sales.update`), `CourierHandoverSheet` (Courier cluster, `sales.view`) and `ToChase` (CRM cluster, `crm.view`; marking needs `crm.manage`); route `courier.handover-sheet` (`CourierHandoverSheetController`) for the print view; `CourierHandoverSheetService` takes bookings of one courier whose `booked_at` falls in the company's day and drops not-booked, pending, cancelled and failed ones. The invoice already prints a Code 128 barcode of the order number (`App\Support\Code128`), which a USB scanner and the camera page both read, so no QR code was added. Tests: `ScanParcelsTest` (6), `CourierHandoverSheetTest` (4).
## [2.87.2] - 2026-10-19

**Release type:** Patch/Fix Update

### Fixed

- **WhatsApp Web: a picture or voice note that does not open in the Inbox now says why.** Instead of a silently broken picture or a player that does nothing, the message shows the server's answer (status, file type and reason, for example "the file is not in the server storage"), so the real cause can be fixed quickly.
- **One WhatsApp file that can no longer be downloaded no longer holds up all the others.** The bridge used to wait about 4 minutes on every expired picture or voice note before trying the next, which starved new media; each file is now retried on its own schedule while the rest go through.
- **Audio and video can be played and wound forward from any point** (and play on Safari / iPhone): the Inbox file route now supports byte ranges and sends the exact file type WhatsApp declared.

### Technical Notes

- `ConversationMediaController` returns `206` / `416` for `Range`, sends `Accept-Ranges`, `Content-Length` and an exact `Content-Type` (declared audio/video type wins over byte sniffing; generic sniffed types fall back to the declared type). For an authorized user a missing or unreadable file answers JSON `{message}` with status 404 / 500; unauthorized requests stay a plain 404.
- Inbox: messages with a saved file get a small Alpine `error.capture` handler that fetches the same URL with `Range: bytes=0-0` and prints the HTTP status, content type and reason. 5 new lang keys (en + bn).
- `whatsapp-bridge`: `Outbox` option `independent` (used by the media queue only) defers a failed entry in memory with its own backoff and continues with the next one; the event queue is unchanged. Redeploy the ERP and the `whatsapp-bridge` app to get this. Tests: 2 new bridge tests, 5 new `WhatsAppWebArchiveTest` tests.
## [2.87.1] - 2026-10-19

**Release type:** Patch/Fix Update

### Fixed

- **Opening an old admin link while signed out (for example a bookmarked `/admin/users`) showed the "this page has a problem" error instead of the sign-in page.** The old admin addresses, the order print pages and the report downloads are protected by a plain sign-in check that tried to send a signed-out visitor to a page named `login`, which this app does not have. The visitor is now sent to the admin sign-in page and, after signing in, returns to the page they asked for. It also stops filling Deploy Error Logs with `module:page:admin.clusters.legacy` ("Route [login] not defined").

### Technical Notes

- Guest redirect: `bootstrap/app.php` sets `$middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'))`, replacing Laravel's default `route('login')`. Every plain `auth` route in `routes/web.php` is under `/admin`, so the admin login is the right target; Filament's own panels (`/admin`, `/portal`, `/investor`) use their own authenticate middleware and are unchanged. No migration, no new string. Tests: `AdminGuestRedirectTest` (5).

## [2.87.0] - 2026-10-19

**Release type:** Minor Feature Update

### Added

- **Pay a cycle's profit without waiting for sales.** Owner decision 2026-10-18: investors in a group are paid their profit share every two months whether or not the goods have sold, so the payout must not depend on sales. **Calculate & Settle** on a project now asks **How is the amount decided?**: *From the sales revenue* (as before) or *Profit entered by hand (no sales needed)*. With a hand-entered profit staff type the **net profit to distribute** (the form shows the investors' share of it as they type); the investors' 40% (the project's own split), the partners' and the company's shares, the rate per lac and each investor's payout are worked out exactly as for a sale, and a group project still pays the profit only while the principal stays in the group. The cost-proof tick-box is not asked, because the costs are not used. The amount stays with staff: nothing is read from sales, and nothing is paid until the settlement is confirmed and each payout is marked paid as usual. A hand-entered settlement is labelled **Profit entered by hand** on the settlement page, and the investor's printable report shows the profit as *declared by the company* instead of a selling amount, gross profit and cost tables that were never real. A loss is still settled from the sales revenue; a hand-entered profit cannot be negative. Settlements made before are unchanged (they read as *From the sales revenue*).

### Technical Notes

- Manual-profit settlement: migration `2026_10_18_100000_add_basis_to_project_settlements` adds `project_settlements.basis` (`sales` default / `manual`, guarded by `hasColumn`); `ProjectSettlement::BASIS_SALES` / `BASIS_MANUAL`, `isManualBasis()`, `basis` is immutable like the other settlement figures. `SettlementService::calculateAndSettle()` takes a last optional `?float $manualNetProfit`: when set it ignores `$totalRevenue` and the cost-proof check, stores `total_revenue = total_cost + profit` so the stored figures still add up, and records `basis = manual` (also in the audit entry). Existing callers and the sales path are unchanged. Settle modal in `ViewInvestmentProject`, summary in `ProjectSettlementResource` (selling amount and gross profit hidden when manual) and `investments/reports/investor-payout.blade.php`. Tests: 8 new in `InvestmentSettlementTest` and 1 in `InvestorGroupExitTest`, mutation-checked (cost check kept for manual, basis not recorded). **Deploy: run `php artisan migrate --force`.** 10 new interface strings in `lang/en.json` / `lang/bn.json` (Bangla to review).

## [2.86.5] - 2026-10-19

**Release type:** Patch/Fix Update

### Fixed

- **WhatsApp Web chats showed "নাম পাওয়া যায়নি" instead of the customer's name.** WhatsApp shares customer names under a hidden ID (LID) while the chat is saved under the phone number, so the names never found their chats. The bridge now learns which number belongs to which hidden ID before it sends the names, and the ERP matches a name by number or by that hidden ID, whether the name or the chat arrives first.
- **Chats that were already saved without a name get one.** New **Fill missing names** button on CRM → WhatsApp Web Login (it also runs by itself a couple of minutes after the first full history sync finishes). A name entered by staff is never replaced.
- **Videos and voice messages now play in the Inbox** (video and audio player instead of nothing). A media message whose file could not be saved shows a short note instead of an empty bubble.

### Changed

- **Inbox: a WhatsApp chat without a name now shows the contact's phone number** (for example +8801859963681) instead of "নাম পাওয়া যায়নি", in the conversation list and at the top of the chat. Chats with neither name nor number, and Messenger chats, keep the old text.
- **New WhatsApp messages reach the Inbox within seconds.** A message sent or received on the phone is saved the moment the bridge delivers it (no queue wait), and the Inbox checks for news every 3 seconds instead of re-drawing itself every 8 seconds, so a new message shows up in about 3 seconds while the server does less work. Messages that arrived while the bridge was offline (a restart or redeploy) in the last 24 hours now count as new (unread badge, reopened chat, new lead) instead of being filed as old history.

### Technical Notes

- `whatsapp-bridge`: `lidPairsFromMessages()` reads LID ↔ phone pairs from message keys (`remoteJid` / `remoteJidAlt`); `Session.learnLids()` runs before contacts are normalized, and `archiveContacts()` asks WhatsApp's LID mapping for any LID still unknown. Redeploy the `whatsapp-bridge` app to get this.
- `WhatsAppWebArchiveService::upsertContact()` resolves a LID-only name through `whatsapp_contacts.lid`, merges a LID-keyed row's names into the number's row and names chats keyed by number or LID; `storeMessage()` now records the chat's LID on the contact. New `refreshNames()` (re-applies saved names, learns LID ↔ phone from `conversation_messages.raw_payload`) runs in the new `RefreshWhatsAppWebNamesJob`, so a queue worker must run. No migration.
- Near real time: `WhatsAppWebWebhookController::importMessages()` runs live batches of up to 25 messages with `dispatch_sync` (history stays queued; a failure returns 500 so the bridge's outbox retries). `Inbox::checkForChanges()` (`wire:poll.visible.3s`) compares a cheap signature (`max(last_message_at)`, `max(id)`, and for the open chat `max(id)` / `max(updated_at)` of its messages) and calls `skipRender()` when nothing changed; a full refresh runs on a change and at least every 30 seconds. Bridge: `splitLiveMessages()` treats `append` upserts from the last 24 hours as live. This is polling, not push - true push needs a websocket service (Laravel Reverb) on Coolify.
- `Conversation::inboxDisplayName()` (WhatsApp provider only) is used by the Inbox; `profileDisplayName()` is unchanged because the Messenger profile lookups compare against its "name not found" result. The avatar of a number-only name shows `#`.
- Inbox: `<video controls>` / `<audio controls>` for archived media; media is still served by `ConversationMediaController` (no byte-range support yet, so seeking inside long videos and Safari video playback may not work). 4 new lang keys (en + bn). New tests in `WhatsAppWebArchiveTest` (names in both orders, refresh, no overwrite, history trigger, page button, inbox media, media route) and 2 in the bridge's `normalize.test.js`.
## [2.86.4] - 2026-10-19

**Release type:** Patch/Fix Update

### Added

- **WhatsApp Web Login now shows why a connection failed.** Under the status, the page shows the bridge's last error and its code (for example "Connection Terminated (428)"), so a stuck "Reconnecting" or a QR scan that ends with "Couldn't link device" is no longer a guessing game.
- **The device identity the bridge shows in WhatsApp's Linked devices list can be changed from Coolify** (setting `BRIDGE_BROWSER`, default `ubuntu:Desktop`), without a code change. WhatsApp may refuse some identities, so this makes it quick to try another one.

### Technical Notes

- `whatsapp-bridge`: new optional env var `BRIDGE_BROWSER` as `<system>:<browser>` (systems `ubuntu`, `macos`, `windows`, `baileys`), read by the new `src/browser.js`. A wrong value stops the bridge at start with a clear message. It applies the next time a QR code is scanned. `GET /health` now also returns `browser`.
- `whatsapp-bridge`: every closed WhatsApp connection is logged as `connection closed` with `code` and `reason`; `GET /sessions/{id}` now returns `last_error_code` next to `last_error`. The ERP page shows both while the device is not connected.
- Redeploy the `whatsapp-bridge` app in Coolify to get this. New lang key "Last error" (en + bn). Tests: bridge `test/browser.test.js` (5), two new page tests in `WhatsAppWebArchiveTest`.
## [2.86.3] - 2026-10-19

**Release type:** Patch/Fix Update

### Fixed

- **Report PDF and CSV buttons showed the file as raw text instead of downloading it** (Reports and Reports → Supplier Report). The admin panel opens links without reloading the page; for a file link that painted the PDF bytes into the page. These links now open as normal downloads.

### Technical Notes

- `AdminPanelProvider::spaUrlExceptions()` lists `*/admin/reports/export/*` and `*/admin/reports/supplier/*`, so Filament no longer adds `wire:navigate` to those links. Another file link that Filament renders in the panel needs the same exception. Tests: `ReportDownloadLinksTest` (3).

## [2.86.2] - 2026-10-19

**Release type:** Patch/Fix Update

### Fixed

- **WhatsApp Web Login (QR) never showed a QR code.** The status stayed on "Reconnecting" because WhatsApp now closes a login that announces itself as the Mac OS or Windows desktop app. The bridge now announces itself as a Linux desktop device (it still asks the phone for the full chat history), and the QR code appears within a second. Redeploy the `whatsapp-bridge` app in Coolify to get the fix.

### Technical Notes

- `whatsapp-bridge/src/session.js`: `Browsers.macOS('Desktop')` → `Browsers.ubuntu('Desktop')`. Checked 2026-10-19 against WhatsApp's servers with Baileys `7.0.0-rc14`: Mac OS + Desktop and Windows + Desktop were closed with 428 "Connection Terminated" after the handshake; Ubuntu + Desktop, Baileys + Desktop, and Chrome/Safari identities received a QR code in under a second. The platform type stays "Desktop" and full-history sync stays on.

## [2.86.1] - 2026-10-18

**Release type:** Patch/Fix Update

### Changed

- **Every SMS wording now follows the BulkSMSBD rules** (Settings → SMS Templates). The rules come from BulkSMSBD's own Developers page and API notes:
  - **Code messages (OTP, password reset)** read exactly **"Your {brand or company name} OTP is XXXX"**, the OTP format BulkSMSBD asks for. The built-in wording is now just that, for shop customers, resellers and investors alike (for example "Your Tasneem Knitting Investment Portal OTP is 123456."), always in English, whatever the person's language. The longer wording ("Use it to log in. It expires in 10 minutes.", and the investors' "login" / "password reset" reason) is gone because the format has nothing after the code. The page only lets the owner change the brand part, and a code message that is not in this format is refused on save (and ignored at send time, the built-in text goes out instead).
  - **No single quotation mark (')** in any SMS: BulkSMSBD refuses it. The page refuses wording with one, and a customer's or investor's name with one has it dropped when the SMS is sent.
  - **At most 3 SMS parts** per message (160 characters a part in English, 70 with Bangla letters). Each box shows the characters and parts used while typing.
  - A notice appears when the SMS sender ID is a masking name (letters): BulkSMSBD sends masking SMS only in Bangla (error 1012), so English messages would be refused. A numeric sender ID has no such limit.
- **Suggested wording** for every order status and investor project message: a **Use suggested wording** button on each box fills in a short English message that follows the rules, ready to edit and save. Nothing is sent until the owner saves a message.

### Technical Notes

- New `App\Support\BulkSmsBdPolicy` (OTP format check, single-quote rule, GSM-7 / Unicode part counting, masking sender check). `SmsTemplateService::problem()` applies it on save, `render()` applies `clean()` to every message and falls back to the built-in text for a code template that is not in the OTP format. `render()` no longer takes a locale; the investor and customer code messages are not translated (their `:portal ... OTP is :code. It expires in 1 minute.` language keys and `:company Investment Portal` were removed). Tests: `BulkSmsBdPolicyTest` (7), and new cases in `SmsTemplatesTest` and `InvestorPortalTest`.
## [2.86.0] - 2026-10-18

**Release type:** Minor Feature Update

### Added

- **Reports → Supplier Report**: one supplier's whole story from buying to selling, for **one product or all of the supplier's products**, with a **Download PDF** button on the same page (owner request 2026-10-18).
  - Supplier info (name, company, phone, email, address, what is still payable to the supplier).
  - The dates the goods came in, one row per purchase, with a date filter on the purchase date.
  - Total landed cost (purchase price plus the China-to-BD costs), sales, average sale price per unit, units billed / short / damaged / sold / still in stock.
  - **Gross profit** = sales − landed cost of the units sold − the loss on short and damaged units.
  - **Net profit** = gross profit − the costs paid after the goods reached the house (packaging, ad, other) − Meta ad spend linked to the product.
  - A purchase-by-purchase table and a product-by-product table, with a total row in the PDF.
- **Purchases now record short and damaged units.** Each purchase line has **Short Qty** (billed, never delivered) and **Damaged Qty** (delivered, not sellable). The billed quantity and the amount owed to the supplier do not change.
- **Purchases → Costs After Arrival**: packaging, ad and other costs paid per product after the goods reached the house, one line each (product, cost name, amount). They feed the Supplier Report only; the purchase total and supplier balance are unchanged.

### Changed

- **A received purchase now adds only the sellable units to stock** (billed − short − damaged). Purchases with no short or damaged units behave exactly as before.

### Technical Notes

- Supplier Report: migration `2026_10_18_000100_add_loss_quantities_and_product_costs_to_purchases` adds `purchase_items.short_quantity` / `damaged_quantity` (unsigned int, default 0) and `purchases.product_costs` (JSON `[{product_id, label, amount}]`); additive and safe to run on live data, no new company-owned model. `PurchaseItem` rejects negative values and short + damaged above the billed quantity; `sellableQuantity()` feeds `PurchaseWorkflowService::syncStockMovements()` (a product whose every unit was lost gets no stock movement). Landed cost is unchanged (`allocated_cost` / `landed_unit_cost` stay on the billed quantity).
- `App\Services\SupplierReportService` holds the rules (documented in its class comment): only received purchases, date range on the purchase date, sales (accounted statuses, all time) handed to a product's units first-in-first-out (opening stock, then purchases by date, across all suppliers), costs after arrival and Meta ad spend (`meta_ads.spend` by `product_id`) counted in proportion to units sold. Page `App\Filament\Pages\SupplierReport` (Reports cluster, `reports.view`); PDF route `reports.supplier.pdf` (`reports.export`, audit log `report_exported` with `type = supplier`). The PDF is always English because dompdf cannot shape Bangla. Tests: `SupplierReportTest` (9).
## [2.85.1] - 2026-10-18

**Release type:** Security Update

### Security

- **Sign-in is now judged before the password is checked, so a flood of guesses can no longer burn the server's CPU.** The password hash is slow on purpose, which makes it the most expensive thing an attacker can make the server do: 50,000 guesses a minute from 30,000 addresses stay under every per-IP limit and under every per-account lockout (each address tries under twice, each account about four times), and every guess still costs a full hash while real users wait. A new gate looks at the request first and answers *go ahead*, *wait*, *challenge* or *refused*:
  - A **device** (IP + browser) that gets 5 passwords wrong for one account in a minute waits.
  - A **device the account has signed in from before** is never locked out by other people's guessing, so a stranger who only knows a phone number can no longer lock the real owner out. The browser is remembered with a signed cookie after a password sign-in; changing the password ends every remembered device.
  - An **unknown device** waits while the account has had 20 wrong passwords from anywhere in 15 minutes, or while the whole site is under attack (300 wrong passwords a minute), and when the server's budget of 600 password hashes a minute for unknown devices is used up.
  - A **client that only ever fails** (30 wrong passwords in 10 minutes and not one successful sign-in from the same browser and network) is told "login failed" and no hash is computed.
  - It covers the storefront and reseller portal sign-in, the Next.js site's sign-in, the investor portal (which had no per-account limit at all) and the **admin panel** sign-in.
  - Every number can be changed with an environment variable (`config/login_protection.php`, `docs/login-protection.md`); `LOGIN_PROTECTION_ENABLED=false` turns the gate off.

### Fixed

- **A phone number could be guessed under many spellings to dodge the per-account lockout.** The storefront and reseller sign-in counted wrong passwords under the text typed, so `01712345678`, `+8801712345678` and `8801712345678` each had their own five tries. The phone is now normalised first. The count is also kept per company: the same phone number in two companies no longer shares one counter.
- **An unknown phone or e-mail answered faster than a wrong password,** which showed who has an account. It now costs the same time.
- **The reseller portal sign-in's "too many attempts" answer is now a notice with the wait,** instead of an error attached to a field name (`identifier`) its form does not have.
- **The investor portal no longer writes an audit row for a guess that was refused,** so a flood of guesses cannot flood the audit log.

### Technical Notes

- New: `App\Services\LoginGate` (decides with `begin()`, counts with `failed()` / `succeeded()`, device trust with `issueDeviceToken()` / `rememberDevice()`), `App\Support\LoginClient`, `LoginAttempt`, `LoginThrottled` (a `ValidationException`, so existing `catch` blocks keep working), `config/login_protection.php`, `App\Filament\Auth\AdminLogin` (the admin panel's `->login()`; it lives outside `Filament/Pages` so Filament does not discover it as a page). All counters are `RateLimiter` keys in the cache: production needs `CACHE_STORE=redis` for them to be shared between workers. No migration.
- `CustomerAccountService::attemptLogin()` and `InvestorPortalAccountService::attemptPasswordLogin()` take an optional `LoginClient`; both services gained a `LoginGate` constructor argument. The old `storefront-login:<identifier>` limiter key is gone.
- The gate fails open: if it throws (the cache is down) the error goes to Deploy Error Logs as `module:login-gate` through `ModuleGuard::run()` and the sign-in goes on to the normal password check.
- Next.js API (`docs/api/storefront-api.md` §6): `POST /account/login` accepts `device_token` (or the header `X-Device-Token`) and answers `data.device_token` on success; a `429 too_many_attempts` now also carries `challenge`. The visitor's `client_ip` and `client_user_agent` decide the device. The website works unchanged without the token; keeping it in an httpOnly cookie is the website's part.
- Not done yet: a CAPTCHA for the *challenge* answer (until then it is a wait), and device trust after a one-time-code sign-in. One new interface string in both language files. Tests: `LoginGateTest` (19).
## [2.85.0] - 2026-10-18

**Release type:** Minor Feature Update

### Added

- **Settings → SMS Templates**: one page where the owner writes the wording of the SMS the system sends. Each message is plain text with placeholders such as `:name`, `:order` or `:code`, and the page lists the placeholders under every box.
  - **Order status**: a message for each order stage (Confirmed, Processing, Shipping, Delivered, Completed, Cancelled, Returned, Refunded). The customer is texted when their order reaches that stage, using `:name`, `:order`, `:status`, `:total`, `:due`, `:tracking` and `:company`. A stage left empty sends nothing, so no customer is texted until the owner has written a message. Imported past orders, reseller store orders, customers without a phone and numbers on the opt-out list are never texted.
  - **OTP and password reset**: the sign-up, login and password-reset codes of shop customers and resellers, and the login and password-reset codes of investors. Left empty, the built-in wording is sent exactly as before; a message must contain `:code`, or the page refuses it.
  - **Investor projects**: three messages to every investor of a project: **Project started**, **Project completed** and **Profit settled** (when the settlement is confirmed, with `:profit` and `:payout`). A group's next project is announced right after the previous one is completed. Empty means nothing is sent.

### Changed

- The **Project completed message** moved from Investor Portal Settings to Settings → SMS Templates. The message already written there is moved over by the update, so nothing needs to be typed again.

### Technical Notes

- New table `sms_templates` (`company_id`, `key`, `body`, unique per company and key) with the `SmsTemplate` model (`BelongsToCompany`, added to `MultiCompanyIsolationTest`). The migration copies a non-empty `companies.investor_project_completed_message` into the `investor_project.closed` row; the old column is left in place and no longer read.
- `SmsTemplateService` holds the list of messages, their placeholders and built-in wording, and renders a template. `OrderStatusSmsService` reacts to `OrderStatusTransition::created` (the same stage mapping as the Meta status events) and queues `SendOrderStatusSmsJob`; `InvestorProjectMessageService` sends the project messages (`SendInvestorProjectStatusSmsJob` for a project outside a group changing to running or closed, and for a confirmed settlement; the group cycle listener `SendProjectCompletedMessages` for a group). Both use the company's SMS gateway row that has a gateway (the order's own site first, then the main site) and send each investor once per project and status.
- `CustomerAccountService` and `InvestorPortalAccountService::sendCode()` now build their code SMS through `SmsTemplateService::render()`. Tests: `SmsTemplatesTest` (11), plus new cases in `ProjectCompletedMessageTest` and `InvestorPortalTest`.
## [2.84.0] - 2026-10-17

**Release type:** Minor Feature Update

### Changed

- **Customer address moved into the Customer box** on the customer view page, and the **customer list now has a Customer Address column** that wraps long addresses (up to 3 lines) and can be searched.

**Technical Notes:** `CustomerInfolist` moves the `address` entry into the Customer section; `CustomersTable` adds a wrapped, searchable `address` column. New string "Customer Address" is in `lang/en.json` and `lang/bn.json`. No migrations.
## [2.83.1] - 2026-10-17

**Release type:** Patch/Fix Update

### Fixed

- **Imported Nuport orders of products with no stock** no longer ask for stock when edited. Saving an imported order on its Edit page showed the "Not enough stock" popup for any product whose stock was 0 and offered to add stock, which would have put stock in that was never there. Imported orders are history and never take stock, so the popup is skipped for them (like WooCommerce orders).
- **The Edit order page shows the product name of an old order whose product is switched off.** The product box only listed active products, so a line for an inactive product (such as the products "Create missing products" makes) showed an empty name. The product the line already has is always listed now.

### Technical Notes

- `HandlesStockShortages::haltWhenStockIsShort()` returns early for a saved order with `isHistoricalImport()`; `OrderForm`'s item `product_id` select adds `OR products.id = <the line's product_id>` to its active-products query. Test: `NuportOrderImportTest::test_an_imported_order_of_a_zero_stock_product_shows_normally_and_never_asks_for_stock`.
## [2.83.0] - 2026-10-17

**Release type:** Minor Feature Update

### Added

- **Import Nuport orders: SKU mapping and "Create missing products"**, so no Nuport order is skipped because of a product the ERP does not have.
  - **SKU mapping**: a box in the import dialog with one line per retired or renamed Nuport SKU, written `NUPORT-SKU = ERP-SKU`. Those lines use the ERP product or variant with that SKU. The mapping is remembered for the next import. The preview shows which mappings were used and how many orders each covered; a line whose ERP SKU does not exist is ignored and listed, never guessed.
  - **Create missing products** (off by default): a product that no SKU or mapping finds is created **inactive, with stock 0 and cost price 0**, using the Nuport SKU, name and the last non-zero unit price. A line with no SKU gets the SKU `NUPORT-<name>`. The preview lists every product that would be created. Set the real cost price in the product afterwards; the cost of already imported order lines stays 0.
  - The command takes the same two settings: `php artisan orders:import-nuport <file> <company-slug> --map=<file> --create-missing --apply`.

### Technical Notes

- `NuportOrderImportService::run()` gained `$skuMap` (text) and `$createMissing`. The mapping wins over a direct SKU match. With `$createMissing` the service plans every invoice once first so a new product carries the last non-zero price in the file. New products are saved just before the order that needs them; a second run finds them by SKU (or by name for the SKU-less ones) and creates nothing twice. The remembered mapping is an `app_settings` row `nuport_import_sku_map_<company id>`. Tests: `NuportOrderImportTest` (22 tests).
## [2.82.0] - 2026-10-17

**Release type:** Minor Feature Update

### Added

- **Orders → Import Nuport orders**: copies the wholesale customers' past orders from Nuport's **Export As CSV** into the ERP, so each customer's purchases, payments and status are in one place.
  - It starts as a **preview** that saves nothing: how many orders are new, which products are not found, the totals per status, and how many customers are new or already exist. Turn **Preview only** off to save.
  - Each order keeps its Nuport number (SO-1309), date, lines, delivery fee, discount, paid amount and due, delivery status, notes, and the Steadfast consignment ID in the note.
  - Statuses: Delivered = Completed, Approved = Confirmed, In-Transit and Processing = Processing, returned parcels = Returned, Cancelled = Cancelled. Cancelled orders marked "Test Order" or "Fake Order" are skipped; other cancelled orders come in without any payment.
  - Customers are matched by phone number (01712345678, +8801712345678 and 8801712345678 are one customer); a new phone creates a customer.
  - An order whose product is not found by SKU is skipped and listed; create the product and import the same file again.
  - **It never changes stock or any account balance**, and sends no new-order alerts. Payments are history only, with no account.
  - Importing the same file again never duplicates orders. A newer export refreshes the status, delivery fee, discount and payment of orders already imported.
  - The same import also runs from the server: `php artisan orders:import-nuport <file> <company-slug> --apply` (without `--apply` it only previews).

### Technical Notes

- `App\Services\NuportOrderImportService` (also `orders:import-nuport`, and `ListOrders::importNuportOrdersAction()`) reads Nuport's export, one row per product line with the order money only on the first row of each invoice. No migration. New `Order::SOURCE_NUPORT_IMPORT` (`nuport_import`, fits `orders.source` varchar 32) marks an imported order; `Order::isHistoricalImport()` makes `OrderWorkflowService::sync()` skip stock movements (for create, status change and later edits), `OrderNotificationObserver` skip the new-order and status alerts, and the `saved` hook skip `CustomerRiskService::evaluateOrder()`.
- The invoice becomes `order_number` and `external_reference` (unique per company), so a second run finds the order again. `shipping_zone` is stored as `imported` so `Order::creating` does not replace Nuport's delivery fee with the zone fee. Lines are saved with `OrderItem::withoutEvents()` and the workflow is synced once per order; the real export (1,340 invoices, 2,311 lines) imports in about 20 seconds in the test database. Discount = list-price total of the lines minus Sales Amount, so the ERP total equals Sales Amount plus Delivery Fee; invoices where the file's own columns disagree (32 in the real export) are listed in the preview. One `OrderPayment` per order, `account_id` NULL, so no `TransactionLedger` row is posted; customer balances come from the imported due of accounted orders (`Customer::syncCurrentBalance()`), so a customer who already has an opening balance for the same dues is counted twice (the preview counts such customers).
- Deploy note: the upload action runs in the web request (`set_time_limit(0)`, `ignore_user_abort`); on a slow server or a short proxy timeout use the artisan command. Tests: `NuportOrderImportTest` (17 tests).
## [2.81.1] - 2026-10-16

**Release type:** Patch/Fix Update

### Changed

- **Investor portal SMS codes now read "{company} Investment Portal login OTP is 123456. It expires in 1 minute." (or "... password reset OTP is ..."), and the code is valid for 1 minute instead of 10.** Owner request 2026-10-16. Before, the text was "Your {company} OTP is 123456. Use it to sign in to the investor portal. It expires in 10 minutes." The message follows the investor's language, and the sign-in and forgot-password pages now say "It expires in 1 minute." A second request inside the first minute still keeps the code already sent. Customer and reseller codes are unchanged.

### Technical Notes

- `InvestorPortalAccountService::sendCode()` words its own message (portal name = `:company Investment Portal`, reason = `$purpose` `login` or `reset`); `CODE_VALID_MINUTES` 10 -> 1. The two "Use it to ... the investor portal" keys in `lang/` were replaced by three new keys, and the helper text key on `Investor\Pages\Auth\Login` / `ForgotPassword` changed from "...10 minutes." to "...1 minute.". Test: `InvestorPortalTest::test_the_investor_code_sms_names_the_portal_and_the_reason_and_lives_one_minute`.
## [2.81.0] - 2026-10-16

**Release type:** Minor Feature Update

### Added

- **IMS investor feed.** A new key type **Investor sync (investors and payouts for IMS)** opens `/api/v1/sync/investor/*`: investors (name, phone, NID, group, approved bank accounts), groups, projects with their progress percentage, investments, and payouts with amount, status, date and a link to the payment screenshot (`GET /payouts/{id}/proof`). Read only, one company per key, and never a customer or a supplier. Owner decisions 2026-10-15 (details and the planned "IMS reports a payout" step in `docs/api/ims-sync-api.md`).

### Technical Notes

- `CompanyApiKey::SCOPE_INVESTOR_SYNC`, `ResolveCompanyFromApiKey` hint per scope, `InvestorSyncFeed` (extends `ImsSyncFeed`), `InvestorSyncController`, `Investor::groupMemberships()`. Two interface strings added or reworded in both language files (the key-type name and the key helper text). Tests: `InvestorSyncApiTest` (10).
## [2.80.0] - 2026-10-15

**Release type:** Minor Feature Update

### Added

- **Admin: one list to set the suggested selling price of every variation.** Owner request 2026-10-15 ("একসাথে অনেকগুলোর দাম বসানোর টুল"). **Resellers → Variation selling prices** lists every active variation of a variable product with its wholesale price (read only) and its suggested selling price, which is typed straight in the row and saved on leaving the field (blank clears it). A filter shows the variations with no suggested price yet, a product filter and search narrow the list, and two bulk actions give the selected rows the same price or clear it. There is no percentage rule. A variation with no suggested price is still not sold in a reseller's store until the reseller sets a price.
- **Damaged goods are counted, and labelled by where they came from.** Owner decision 2026-10-15. A stock movement of type **Damage** now has a required **Damage source**: *Customer return damage*, *Shipment damage* (found damaged when a carton or shipment is opened) or *Other damage*. It shows in the stock movement list (with a filter) and in a product's movement history, and the **Total Damage** card on Products now opens the damage list. Older damage entries stay unlabelled. On a returned or refunded order, **Put returned goods back in stock** now takes two numbers per item, *Good pieces* (added back to stock) and *Damaged pieces* (counted as customer-return damage and not added to stock), so a damaged exchange or return is counted too.
- **Reseller portal: tab bar on phones.** Owner request 2026-10-15. Below the large-screen width, approved resellers get a bar fixed to the bottom of the screen with Dashboard, Products, a raised **Create order** button, Orders and Earnings; the page being viewed is highlighted. The sidebar still holds everything else. It is not shown on large screens, on the sign-in and apply pages, or to a pending application.
- **Reseller dashboard: announcement, first steps and best sellers.** Owner request 2026-10-15. (1) **Announcement:** Reseller Settings has a new **Portal announcement** section (on/off and the text, up to 500 characters; the text is required while it is on); the text shows in a banner at the top of every approved reseller's dashboard and is escaped, never taken as HTML. (2) **Get started:** a checklist of four steps (name your store, add products, add a payout account, get your first order), each ticked from the reseller's own records and linking to where it is done, with a progress bar; it disappears when all four are done. (3) **Best-selling products:** the five products this reseller sold the most pieces of in the last 30 days, from their own confirmed, processing and completed orders only.
- **Public reseller guide: the return charge.** Owner request 2026-10-15. The guide now says that when an order is returned a return charge is taken from the reseller's deposit, once for that order, for store-delivered and own-courier orders alike. It appears only while the return charge in Reseller Settings is above zero, and the amount is shown only when **Show money figures on the guide** is on.
- **Reseller Settings: "Test subdomain setup" and a Cloudflare + Coolify guide.** Owner request 2026-10-15. The button opens a made-up name under the company's domain and says which step is missing: the wildcard DNS record, or the wildcard SSL certificate (with the reason). `docs/reseller-subdomain-setup.md` (in Bangla) walks the owner through the Cloudflare and Coolify side step by step; the owner does that part.

### Changed

- **A returned or refunded order can never be moved to trash.** Owner decision 2026-10-15, so the returned goods and the damage counted from it are never lost. The order page's delete button is hidden, the list's *Move to trash* skips these orders (and says so), and the order model refuses to be soft-deleted in these statuses. Permanent deletion from the trash is unchanged.
- **Restoring a trashed order brings its sale back.** Owner decision 2026-10-15. Restoring an order from the trash recreates its stock sale for every status, including an order that was returned when an earlier version let it be trashed. (An order whose stock is short still cannot be restored, and the message says so.) Taking an order's stock effects out now also removes the damage written off for it.

### Technical Notes

- Stock rules (2026-10-15): migration `2026_10_15_120000_add_damage_source_to_stock_movements` adds nullable `stock_movements.damage_source`; `StockMovement::damageSources()`, `StockMovementService::prepareForSave()` clears it on non-damage rows and refuses an unknown value (the form requires it only for new damage entries, so the IMS sync and older code paths still work). A damaged returned piece is booked as a `return` (+n) and straight away a `damage` (-n, source `customer_return`, tied to the order), so on-hand stock does not change and `Order::returnedQuantities()` counts it as checked. `OrderWorkflowService::deleteStockMovements()` removes `sale`, then `damage`, then `return` so stock never dips; `restoreFromTrash()` is called from the model's `restored` hook. `Order::canBeMovedToTrash()`, `OrderResource::canDelete()` and a `deleting` hook (not on force delete) enforce the trash rule.
- Variation prices tool: new `ResellerVariationPriceResource` (model `ProductVariant`, Resellers cluster, index only; needs the reseller module and view rights on Products, editing needs update rights). No migration or new model.
- Reseller portal: tab bar is `resources/views/filament/reseller/bottom-nav.blade.php`, drawn from the panel's `LAYOUT_END` hook through `ModuleGuard`; the portal theme CSS adds bottom padding on small screens, so **`npm run build` is needed**. Dashboard additions: migration `2026_10_15_130000_add_reseller_announcement_to_storefront_settings` (`reseller_announcement_enabled` boolean default false, `reseller_announcement` text), `Dashboard::announcement()`, `firstSteps()`, `bestSellers()`.
- `ResellerSubdomainProbe::diagnose()` returns `ok` / `dns` / `https` (with the connection error), `isLive()` uses it. `ResellerGuideController` passes `returnCharge`. Tests: `OrderReturnedStockTest` (14), `ResellerVariationPriceToolTest` (6), `ResellerPortalBottomNavTest` (3), `ResellerPortalDashboardExtrasTest` (7), `ResellerSubdomainSetupCheckTest` (4), `ResellerGuidePageTest` (+1).
## [2.79.0] - 2026-10-15

**Release type:** Minor Feature Update

### Added

- **Admin: reset a reseller's password, or email them a reset link.** Owner request 2026-10-15. On **Resellers** (the list's action menu, and the top of a reseller's View and Edit pages) there are two new actions for a pending or approved reseller, for staff who may edit customers. **Set a new password** lets staff choose one (with confirmation and the usual strength rules): the reseller is signed out on every device and any pending reset code or link stops working. The password is never emailed or written to the audit log. **Email a password reset link** sends a link to the email address the reseller set in their Profile (the action is disabled with a reason when they have none). The link opens the portal's own "Choose a new password" page on the reseller's portal address, works once and expires after 60 minutes (confirmed by the owner 2026-10-15); the reseller picks their own password. The email goes out through the company's own mail settings (Storefront Settings → Customer Notifications & Reminders) or the server's mailer; if neither can really send, the action says so instead of pretending, and a link that could not be sent is not left valid. Both actions are written to the audit log with the staff member. The existing SMS-code reset on the sign-in page is unchanged.
- **Orders: "Put returned goods back in stock".** Owner decision 2026-10-15 (see Changed). On the order page of a **Returned** or **Refunded** order, staff with permission to add stock movements get this action: it lists each item with how many were sold and how many are already back, and staff enter the pieces that are in good condition (damaged ones are simply left out). It adds a **Return** stock movement tied to the order, can be done in parts, and never takes back more than was sold.

### Changed

- **Bangla for "profit" on reseller screens.** Owner decision 2026-10-15: the English still says profit, and the Bangla now reads **আয়** (the column and headings **আমার আয়**) instead of "লাভ"; the admin list of reseller profits reads "রিসেলারের আয়". The reports' own "Profit" ("লাভ") and the investors' "Your profit" are unchanged.
- **A returned order no longer puts its goods back on the shelf by itself.** Owner decision 2026-10-15, for every order (store, website, WooCommerce, reseller portal alike). Marking an order **Returned** (or later **Refunded**) used to delete its sale from the stock ledger at once, so the pieces counted as in stock before anyone had looked at them. Now the sale stays until staff record what is in good condition with **Put returned goods back in stock**. A **Cancelled** order, which never left, still gives its stock back immediately, and so does moving any order to the trash (its sale and any Return movements recorded for it are removed together, so nothing is counted twice). Customer dues, sales reports and the reseller's profit still follow the order status exactly as before; only the shelf waits for staff. Restoring a returned order from the trash does not bring its sale back.

### Technical Notes

- Reseller password reset by staff: no migration (it reuses `customers.password_reset_code` / `password_reset_expires_at`). `CustomerAccountService::issueResetLink()` (random 48-character token, hashed like the SMS code, `RESET_LINK_MINUTES` = 60, lifts an earlier lock), `cancelReset()`, `resetCodeIsValid()` (does not use the code up), `setPasswordByStaff()`; `ResellerPasswordResetService` (`setPassword()`, `sendLink()` with the statuses `sent` / `no_email` / `no_portal` / `mail_not_set_up` / `send_failed` / `not_a_reseller`, audit actions `reseller.password_set_by_staff` and `reseller.password_reset_link_sent`; Laravel signs the whole URL including the host, so the link is generated as if the request had come to the reseller's portal host, even though staff are on the admin host). `StorefrontNotificationService::emailDeliveryAvailable()` (company SMTP filled in, or a default mailer that is not `log` / `array`) and `sendResellerPasswordResetEmail()` with the `ResellerPasswordResetLink` mailable and a plain-text view that prints the link unescaped (`&` in the query string must not become `&amp;`). `ResellerPasswordResetActions` are shared by the Resellers table, `ViewReseller` and `EditReseller`. The portal panel's `passwordReset()` second page is now `ResetPasswordByLink` (Filament's signed `password-reset/reset` route; it looks the reseller up inside the portal's company only and checks the one-time token through `CustomerAccountService::resetPassword()`, so five wrong tries lock it). Tests: `ResellerPasswordResetByStaffTest` (19, including the real portal Livewire endpoint).
- Portal ↔ admin audit: `ResellerPortalAdminFlowTest` (8) drives both sides through their real pages and actions: an order placed on the portal arrives with the right company, source, buyer, lines, variation and prices; a draft holds no stock; the admin's Change status action takes stock from the exact item (a product with variations shows the sum of its variations), gives it back on cancel and on return, makes the profit payable on completion and takes it back on return (plus the return charge); two resellers asking for the last pieces cannot both be confirmed and stock never goes negative; the portal refuses more than the shelf holds; own-courier orders with a variation follow the same rules; companies and resellers see only their own orders. No application bug was found.
- Returned stock rule: `OrderWorkflowService::sync()` only deletes the order's sale movements when `Order::keepsStockOutUntilGoodsAreChecked()` is false (that is true for `returned` and `refunded`); `deleteStockMovements()` now removes the order's `sale` movements first and then its `return` movements; `Order::returnedQuantities()`; new `PutReturnedGoodsBackInStockAction` (header action on `ViewOrder`, needs `create` on `StockMovement`, quantities worked out under a row lock on the order, `reference_type` / `reference_id` point at the order). No migration. Tests: `OrderReturnedStockTest` (8); `ResellerPortalAdminFlowTest` follows the new rule.
- Bangla wording: the reseller-only keys were renamed from "commission" to "profit" and their Bangla changed to "আয়"; new keys `My profit` (আমার আয়), `Reseller profit` / `Reseller profits` (admin list). The shared `Profit` and the investors' `Your profit` keys are untouched.
## [2.78.0] - 2026-10-15

**Release type:** Minor Feature Update

### Added

- **IMS data feed: returns, deleted records and stock-loss values.** `GET /api/v1/sync/sale-returns` lists every returned or refunded sale with its return date, value and lines; orders carry `returned_amount`; stock movements carry their date, the record behind them and an estimated value; deleted expenses and deleted order payments stay in the feed flagged `deleted`, so IMS can tell that a profit figure changed. (Owner request 2026-10-15; details in `docs/api/ims-sync-api.md`.)

### Changed

- **IMS data feed: no supplier information either.** Owner rule 2026-10-15 ("সাপ্লায়ারের তথ্যও investor দেখবে না"). The `suppliers` and `supplier-payments` feeds are removed and purchases no longer name their supplier.
- **IMS data feed: no customer information, ever.** Owner rule 2026-10-15. The `customers` and `customer-payments` feeds are removed, orders no longer carry the customer's name or phone, and the free-text notes on payments, expenses and stock movements are no longer sent (they can name people). IMS is meant to show only a running project's unit sales, never who bought.

### Fixed

- **A refused SMS was counted as sent.** BulkSMSBD-style gateways answer HTTP 200 even when they refuse the SMS (no balance, wrong sender ID, IP not whitelisted) and put the real result in a reply code. The system now reads that code (`response_code` in the JSON, or a plain code): anything other than `202` is a failed send, so a login code that never left no longer shows "sent", and the WhatsApp-to-SMS fallback no longer stops at a refused SMS. The refusal and its meaning (for example 1007 Balance Insufficient, 1032 IP not whitelisted) are written to the log. Gateways that send no reply code keep going by HTTP status.
- **SMS numbers are now sent with the country code** (`8801XXXXXXXXX`, as the BulkSMSBD documentation shows) instead of the stored `01XXXXXXXXX`.
- **Login, registration and password-reset SMS follow the gateway's OTP format** `Your {Company} OTP is {code}. Use it to … It expires in N minutes.` (storefront customers, reseller portal and investor portal). BulkSMSBD asks for this format for OTP messages. The investor portal texts (English and Bangla) changed too.

### Technical Notes
- Migration `2026_10_15_100000` adds `deleted_at` to `expenses` and `order_payments`; both models now soft-delete, and the rest of the ERP still treats a deleted row as gone. Stock movements are deliberately not soft-deleted: shared-stock sums read the ledger without model scopes and would count deleted rows. A deleted stock movement is still found by the daily `ids_only` check.
- No new interface text, so no language-file changes. Tests: `ImsSyncApiTest` 26 (5 new).
- Seven tests that were already red on `main` after the SMS-format change (country-code numbers, OTP wording) were brought in line: `ProjectCompletedMessageTest` (3) reads the sent number without the `88` prefix, `ResellerApplicationVerificationTest` (4) reads the code from the `OTP is` wording. Test-only change.
- `StorefrontNotificationService::sendSms()` reads the reply through `smsGatewayCode()`; known codes are in `SMS_GATEWAY_CODES`. Phone goes through `PhoneLinks::internationalDigits()`.
- Tests: new `StorefrontSmsGatewayTest` (5); OTP-wording assertions updated in `StorefrontApiAccountTest`, `StorefrontCustomerAuthTest`, `ResellerPortalPasswordResetTest`, `InvestorPortalTest`.
- Agent rules (`CLAUDE.md` Commit policy and `AGENTS.md`, which had the opposite "never commit without permission" lines): finished work is now committed and pushed to a topic-named feature branch (`feature/<topic>`) without waiting to be asked; pushing or merging to `main` still needs the owner's explicit approval. No code change.
## [2.77.0] - 2026-10-14

**Release type:** Minor Feature Update

### Added

- **One click to arrange the Next.js footer like the reference site (owner request).** The Footer Builder (Navigation, menus & SEO tab) has a new **Load the starter footer** button on the Next.js row. It fills the Footer Builder and the footer menu with the footer of `zamzamint.netlify.app`: the brand with the about text and the social icons, **Quick links** (Home, Shop, About Us, Contact Us, Promotions), a **Customer Service** column (Delivery Information, Return, Refund & Exchange, Terms and Conditions, Privacy Policy), **Our Office** (address, email, two phone numbers, trade licence line), **Safety Payments** badges (bKash, Nagad, Rocket, Cards) and the bottom bar (copyright, "Developed by Bright Hive Digital", "Developed with ♥ for Bangladesh"). Nothing is saved until **Save changes**, and every word can be edited afterwards. A link to a page (About, Contact, Delivery, Returns, Terms, Privacy) is added only when the site has a published page with that name; the message after loading lists the ones that were left out, so create those pages under Site → Pages and add them. The reference's *Request for Quote* and *Reselling Program* links are not added because the Next.js site has no such pages yet.
- **Bottom bar: a link on the middle text.** The Footer Builder's bottom bar has a new optional **Middle text link**, so "Developed by …" can link to the developer's site.

### Changed

- **The Next.js footer looks like the reference footer.** Section headings, links, payment badges and the bottom bar use the reference's sizes and colours on a dark footer, the brand and office columns are a little wider, phone numbers are separated by commas, and the copyright text now fills `{year}` and `{company_name}` (the Blade footer already did; the Next.js footer showed the tokens as typed).

### Technical Notes

- Starter footer: new `App\Support\StorefrontFooterStarter::layout()` (blocks, Quick links menu and the labels of page links left out; pages matched by normalised title or slug among the site's published pages, `forSite()`), used by the `loadStarterFooter` header action of the Footer Builder section (`NextjsDesignSections::loadStarterFooterAction()`, section key `footer_builder`, visible on the Next.js row only). It only sets the form state (`footer_blocks`, `footer_menu`). The about text is the reference's Bangla sentence, read from `lang/bn.json` whatever language the admin uses. The contact block carries the reference's own office details (address, email, two phones, trade licence) as editable text; they are not read from another company. No migration. `bottom_bar` data gains `center_url` (`StorefrontSiteDesign::footerBlockData()`, cleaned like other links); the Blade footer ignores it. Website: `Footer.tsx` restyled, `{year}` / `{company_name}` filled in the copyright and middle text, `center_url` rendered as a link. New `NextjsFooterStarterTest` (10).
## [2.76.0] - 2026-10-14

**Release type:** Minor Feature Update

### Added

- **One click to arrange the whole Next.js home page like the reference site (owner request).** The Home Page Builder (Website Design tab) has a new **Load the starter layout** button. It fills the builder with every section of `zamzamint.netlify.app` — hero, trust strip (Fast Delivery, Easy Payment, Smart Gadgets, Quality Check), Shop by Category, *Best Deals Today*, *Latest Gadgets*, the *Flash Deal* band, four category rows (*Best Audio Picks*, *Fans & Cooling*, *Kitchen Essentials*, *Beauty & Lifestyle*), *Order Easily on WhatsApp* and the *Wholesale Gadgets in Bangladesh* text — and adds the top bar links *Mobile App* and *Track Order* when there are none. Your categories are matched by name (a spelling such as "Cocker" or "Gift and Crafts" still matches; a row with none of its categories is left out). Nothing is saved until you press **Save changes**, and every word can be edited afterwards. A second button, **Fill empty category details**, adds the short line and icon the category cards show to the categories that have none — a tagline or icon you already set is never changed.
- **A product row can mix several categories.** The *Product row* section's category choice is now a multi-select (up to 8): the row shows one product from each chosen category in turn. Rows saved with one category keep working.
- The *Shop on mobile* section is now the target of the top bar's *Mobile App* link (`/#mobile-app`), and the folded *SEO text* shows the first paragraph and opens the rest with *Read more*.

### Technical Notes

- Starter home page: new `App\Support\StorefrontHomeStarter` (sections, top bar links, category matching by normalised name, blank-only tagline/icon fills) used by two header actions of the Home Page Builder section (`NextjsDesignSections`: `loadStarterLayout` only sets the form state; `fillCategoryDetails` writes the blank category fields straight away, per category only when the user may update it, always inside the settings row's company). `product_row` data now holds `category_ids` (the old `category_id` is merged in by `StorefrontSiteDesign`); `GET /site` serves `category_slugs` plus `category_slug` (the first) for older websites; `PRODUCT_SOURCES['category']` reads "Chosen categories". 14 new keys in `lang/en.json` and `lang/bn.json`. New `NextjsHomeStarterTest` (11).
## [2.75.0] - 2026-10-14

**Release type:** Minor Feature Update

### Added

- **Reseller deposit: a cancelled order gives the money back.** When an own-courier order that was paid (in whole or in part) from the reseller's deposit is cancelled, the amount taken from the deposit goes back to it automatically, once, and shows in the deposit balance beside the portal's user menu. Only the deposit part returns: money the reseller sent to the store (bKash, bank and so on) is not refunded automatically. Staff see **Returned to the reseller's deposit** on the order page. (Owner decision 2026-10-12.)
- **Resellers → Deposits (admin).** A new page lists every reseller's deposit movements: money sent above an order's total, what orders used, what a cancelled order returned, and adjustments made by staff, each with its date, reseller, order, reason and who did it. **Add to deposit** and **Deduct from deposit** change a reseller's deposit by hand; a reason is always required, a deduction can never take the deposit below zero, and each change is kept in the list and in the audit log. The Resellers list has a sortable **Deposit** column and each reseller's page shows the deposit. Permission: Sales view to see, Sales update to adjust. (Owner decision 2026-10-12.)
- **Reseller Settings: choose and add the payment methods resellers pay their advance into.** Under **Own-courier advance payments** the admin ticks which of the store's payment methods (the manual send-money / bank ones on Storefront → Payment Methods) resellers may use, and can add methods that exist only for the reseller portal (name, number, instructions, on / off; they may even reuse the store's name, e.g. a different bKash number). Resellers see the ticked store methods and the reseller-only ones when they place or change an own-courier order. Reseller-only methods never appear at the storefront checkout, in the website API or on the store's Payment Methods page, and changing the store's list never changes them. Until the admin saves a choice, every active manual store method stays available exactly as before. (Owner decision 2026-10-12.)
- **Reseller application: prove the phone number with an SMS code when it already belongs to a customer.** On the reseller portal's **Become a reseller** page, a brand-new number applies as before, but a number that already has a customer record (past orders, a storefront account or an existing reseller) first gets a 6-digit SMS code (valid 10 minutes, five tries, one SMS a minute); the old record is used and its password set only after the code is entered. The form can send the code again or go back to change the number. Without an SMS gateway such a number cannot be proven, so the form says to contact the store. (Owner decision 2026-10-12.)
- **Products with variations (size, colour) now work in the reseller program.** Owner decisions 2026-10-12 and 2026-10-14. There is no separate reseller price on a variation: its own (wholesale) price, plus the company's **Reseller cost per unit** (as for an ordinary product), is what a reseller pays for it. The admin enters a **Suggested selling price for resellers** on each variation (Products → Variations): the market price a reseller sees and their customers pay by default. The reseller can override it with their own customer price per variation, never below what they pay, in the portal (**Products → Variation prices**, or the bulk "suggested + %" action), and earns the difference as profit, the same as ordinary products. A variation with neither a suggested price nor a reseller price is **never sold at the wholesale rate**: the reseller is asked to set a price and, until then, it cannot be bought in their store or ordered from the portal. On a reseller's store each variation shows the reseller's price, the cart and checkout charge it and record the profit, and what the reseller pays is **never** shown there. In the portal a reseller can now order these products (store-delivered or with their own courier, and add them to an own-courier order): they choose the option on each line, and stock is the variation's own. The portal's product list and details show the reseller their cost, price and profit per variation.
- **Reseller advance: pay online with the store's active gateway.** Owner decision 2026-10-14. Under **Paid to** (create order, change order, **Send payment again**) a reseller now also sees **Pay online (ZiniPay / PayStation)** whenever the store has an online payment gateway switched on and set up (Integrations → Payment Gateway), next to the send-money numbers. Choosing it asks for no number or transaction ID: the reseller is taken to the gateway's secure page for exactly what is still owed (after the deposit); when the gateway confirms it (on the way back, or by its webhook if they never return) the payment is entered in the order's Payments History once and the order shows as paid, so staff can pack the parcel without checking anything by hand. An unfinished online payment shows **Waiting for the online payment** with a **Pay now** button on the Orders list, and is replaced when the reseller starts another payment (online or send-money). Staff cannot verify or reject an online payment by hand. Own-courier orders stay available when the store has only the gateway and no send-money number.
- **Reseller return charge.** Owner decision 2026-10-14. When a reseller's order is returned (store-delivered or own courier), a return charge of **50 per order** (not per piece) is taken from the reseller's deposit, once. The amount is set in **Reseller Settings → Money rules → Return charge per order** (0 = no charge). A cancellation before delivery is unchanged (the deposit part goes back); a return gives nothing back. If the deposit holds less, it goes below zero and the reseller's next credits make that up first; a deposit below zero pays nothing on new orders. The charge shows in **Resellers → Deposits**, on the order for staff, and in the reseller's order details.

### Changed

- **Resellers earn "profit", not "commission".** Owner decision 2026-10-14: what a reseller keeps is the price they sell at minus the store's cost, so every reseller-facing screen now says **profit** (the portal's Orders, Products, Earnings, dashboard, create / change order, product details, the public reseller guide, the older store account page and the Reseller Settings help texts, plus the admin list of reseller profits). The arithmetic and the payable-after-delivery rule are unchanged. Investor and channel-partner "commission" screens are different things and keep their wording. Internal names (tables, classes) are unchanged.

### Security

- **A reseller account could be taken over by anyone who knew its phone number.** Applying on the portal with an existing customer's or reseller's number replaced that account's password (and the older plain `/reseller` form overwrote its name and business name). Both now require the SMS proof above; the plain storefront form no longer touches an existing number at all and sends the applicant to the portal's application page.

### Technical Notes

- Deposit refund: `ResellerDepositEntry::TYPE_REFUND` (positive entry, no migration); `ResellerOwnCourierService::refundDeposit()` returns `used - already refunded` for the order under the reseller's row lock (same as `applyDeposit()`), so it is idempotent; it runs from the `Order` `saved` hook when an own-courier order's `status` changes to cancelled (staff transition, WooCommerce sync or a direct update all pass through it). `refundedTo()` feeds the order page. Tests: 5 new in `ResellerOwnCourierOrderTest` (deposit part only, once only, not-cancelled/no-deposit, other reseller untouched, staff view).
- Admin deposits: migration `2026_10_12_100000_add_created_by_to_reseller_deposit_entries` (nullable `created_by` → users); `ResellerDepositEntry::TYPE_ADJUSTMENT` (signed amount, reason in `note`) and `TYPES` labels; `ResellerDepositService::adjust()` validates direction / amount / reason and refuses a deduction above the balance under the reseller row lock shared with `applyDeposit()` and `refundDeposit()`; `ResellerDepositResource` (Resellers cluster, read-only table, header actions `addToDeposit` / `deductFromDeposit`, approved resellers of the current company only); `ResellerDepositEntry` is added to the audit observer list and to `User::MODEL_MODULES` (sales). Tests: `ResellerDepositAdminTest` (13).
- Reseller payment methods: migration `2026_10_12_110000_add_reseller_payment_methods` (`storefront_payment_methods.reseller_only`, unique per `(company_id, reseller_only, name)` replacing `(company_id, name)` — new index created before the old is dropped for MySQL's foreign key; `storefront_settings.reseller_payment_method_ids` JSON, NULL = never chosen); `StorefrontPaymentMethod::scopeForStore()` is applied in `StorefrontCheckoutService::activePaymentMethods()` (storefront checkout + website API) and in the Payment Methods resource; `ResellerOwnCourierService::paymentMethods()` = reseller-only active + (chosen store ids, or all active store methods while NULL); `ResellerSettings::savePaymentMethods()` syncs the repeater (update in place, create, delete unless a `storefront_payments` row uses it — then switched off). Laravel's `Unique::where()` formats a boolean `false` as an empty string, so the store form's name rule uses `0`. Tests: `ResellerPaymentMethodsTest` (10) + 2 in `ResellerOwnCourierOrderTest`.
- Application verification: `CustomerAccountService::findByPhone()` (any spelling of the number, current company only) and `applicationVerification()` (none / sms / unavailable) reuse the storefront sign-up code (`sendRegistrationCode()` / `verifyRegistrationCode()`, cached per company + number, hashed). `Apply` is two steps in one Livewire form (`verifyingPhone`); the pending details wait in the session with only the password hash; the number is normalised with `Phone::normalize()`; an adopted record's `remember_token` is rotated; `register()` no longer calls Filament's base flow (it repeats its create / `Registered` / sign-in steps). `ResellerController::apply()` refuses an existing number. Tests: `ResellerApplicationVerificationTest` (9).
- Variations for resellers: migrations `2026_10_12_120000_add_reseller_prices_to_variants` (`reseller_products.variant_prices` JSON {variant id: customer price}) and `2026_10_15_100000_add_reseller_suggested_price_to_variants` (nullable `product_variants.reseller_suggested_price`, the admin's suggested selling price; `ProductVariant::resellerSuggestedPrice()`; shown in the product form only while the reseller module is on); `ResellerPricing::variantPrice()` = the reseller's pick, else the suggested price, never below the cost, null when neither exists, and `variantBlocked()` makes the storefront treat such a variation as out of stock (`StorefrontProductPage`), refuse it in `CartController` and drop it in `StorefrontCart::items()`; `ResellerOrderPlacementService::suggestedPrice()` / `defaultPrice()` are nullable and a line without a price gets `Enter your price.`; `ProductVariant::resellerCost($costPerUnit)` (= its own price + the company's cost per unit, null without a price) / `baseSalePrice()` and a reseller-aware `effectiveSalePrice()` (same request-scoped `ResellerPricing` context as `Product::selling_price`, so admin, reports, jobs and the Next.js API are unchanged); `ResellerPricing::variantPriceFor()` / `variantBasePriceFor()` / `variantPrice()`; `StorefrontCart` freezes the variation's `reseller_base_price` on the order item, so `ResellerCommissionService` needs no change; `ResellerOrderPlacementService` takes `variant_id` on each line (`lineVariant()`, per-variation stock via `lineStock()`, `basePrice()` / `defaultPrice()` / `suggestedPrice()` take the variation, order items store `product_variant_id` / `variant_label`), and `OrderStockCheckService` already handled variation rows. Portal: `Products::variationRows()` / `saveVariationPrices()` and a `variationPrices` record action; `CreateOrder` / `EditOrder` have an Option select. Not changed: the older storefront reseller account page (`/account/reseller`) still prices only ordinary products. Tests: `ResellerVariationPricingTest` (12), `ResellerVariationOrderTest` (16), +6 in `ResellerPortalProductsTest`.
- Online reseller advance: no migration. `ResellerOwnCourierService::onlineGateway()` (setting on, gateway configured via `PaymentGatewayResolver::isConfigured()`), `paymentOptions()` (manual methods + the pseudo option `ONLINE`), `startOnlinePayment()` (closes unfinished online attempts, creates a pending `StorefrontPayment` with `gateway` zinipay/paystation and `purpose` reseller_advance for `outstandingAmount()`, calls the gateway with a signed portal return URL and the webhook on the company's own domain because `RestrictPortalHosts` serves only `/portal` on the portal host, keeps `payment_url` in `payload`), `recordOnlinePayment()` (called by `StorefrontPaymentService::verifyAndFinalize()` when the gateway marks it completed, and again on a repeat call; under a row lock it enters one `OrderPayment`, identified by a `[payment #id]` marker in its note so the return and the webhook cannot both enter it, and credits any overpayment), `paymentState()` has a new `paying` state. New route `reseller.portal.orders.payment-return` (`ResellerOnlinePaymentReturnController`: signed link with `hasValidSignatureWhileIgnoring`, same company as the portal host, asks the gateway, never trusts the query string). `ViewOrder` offers Verify / Reject only for manual payments. Tests: `ResellerOnlineAdvancePaymentTest` (18).
- Return charge: migration `2026_10_14_100000_add_reseller_return_charge` (`storefront_settings.reseller_return_charge`, default 50). `ResellerDepositEntry::TYPE_RETURN_CHARGE` (negative entry); `ResellerOwnCourierService::chargeReturn()` (called from the `Order` `saved` hook when a reseller order's status becomes returned; takes the company's current setting, the same reseller row lock as `applyDeposit()` / `refundDeposit()`, once per order), `returnChargeOf()`, `spendableDeposit()` (`max(0, depositBalance())`, used wherever an order spends the deposit). The staff deduction in `ResellerDepositService` still refuses to go below zero. Tests: `ResellerReturnChargeTest` (11).
## [2.74.0] - 2026-10-14

**Release type:** Minor Feature Update

### Added

- **Investor portal: new look (minor).** The owner picked design "F" with design "B"'s menu (mock-ups: the "Investor Portal Redesign" canvas, row G).
  - **Menu in groups:** *Overview* (Dashboard, My investments, Statement), *Money* (Payouts, Requests, Bank accounts), *Account* (Documents, Agreements, Profile), then *Channel partner*. The menu sits in a dark sidebar with the company logo at the top and the person (Profile, sign out) at the bottom; the top bar is gone. A logo made for light backgrounds sits on a white chip there; a company's dark-mode logo is shown as it is.
  - **Look:** light grey ground, white rounded tiles, near-black ink with a lime highlight, Hind Siliguri for Bangla and English.
  - **Dashboard:** greets the person by name with today's date. The tiles are: capital at work, with profit received, profit confirmed but not yet paid, and total paid; a ring of **capital by group**; a tile per project with a ten-block progress bar; this year's **project calendar** (paid, profit confirmed, completed, running, upcoming); and the **next payout**, with its four steps (project completed, profit confirmed, payment sent, screenshot added). The profit chart and the latest statement lines follow. Everything a project card said before is still on it.
  - **English / বাংলা switch** in the sidebar and above the sign-in forms. The choice is kept for the browser and on the person's account (the language their portal SMS use too); a staff preview changes only its own browser. Accounts start with no language, so nothing changes for anyone until they use the switch.
  - **Phones:** a bottom menu (Home, Investments, Payouts, Menu).
  - Technical notes: `SetInvestorPortalLocale` middleware (also persistent for Livewire), route `investor/language/{locale}` (`InvestorPortalLocaleController`), `InvestorPortalCards::calendar()` / `nextPayout()`, `Dashboard::capitalShares()`; panel `->topbar(false)`, user menu in the sidebar, ink primary colour, new theme CSS. No migration. Tests in `InvestorPortalGroupViewTest` and `InvestorPortalTest`.

- **Read-only data feed for IMS (`/api/v1/sync/*`).** The ERP can now be read by IMS (`tareksec/ims`), which asked for products, stock, purchases, suppliers, shipments, sales with their payments, customers and expenses.
  - API keys now have a **type**. In **Settings → Integrations → Website API → Generate API key** choose **Website** (the old kind) or **Data sync (read-only feed for IMS)**. A Data sync key opens only the new feed; a Website key cannot read it, and a Data sync key cannot use the website API (`403 wrong_key_scope`). Every key that existed before is a Website key.
  - 14 resources (`products`, `categories`, `stock-movements`, `suppliers`, `purchases`, `supplier-payments`, `containers`, `shipments`, `customers`, `orders`, `order-payments`, `customer-payments`, `expenses`, `expense-categories`) plus `ping`. `GET` only; nothing can be changed through it.
  - Paging by cursor (no skipped or repeated record, even when many were saved in the same second), `updated_since` (inclusive), `status`, `date_from`/`date_to`, `ids_only`. Money is a two-decimal string; times are ISO 8601 UTC. An order carries its lines, its payments with **their own dates** and its costs; deleted orders stay in the feed flagged `deleted`.
  - Limit: 300 requests per minute per key. Contract and answers to IMS's questionnaire: `docs/api/ims-sync-api.md`.
  - Technical notes: migration `2026_10_13_100000_add_scope_to_company_api_keys` (adds `scope`, default `website`); `ImsSyncFeed`, `SyncController`, the `sync-api` rate limiter; `ResolveCompanyFromApiKey` takes the key scope as a parameter. 21 tests in `ImsSyncApiTest`. No webhooks for IMS yet.
- **Investor Groups (group-based investment), step 1 of 6.** Investors now join a **group** instead of a single project. New menu **Investments → Investor Groups**:
  - A group is **Open** (members can be added), **Running** (membership frozen) or **Closed**. Staff add members with their amount, payment details and the usual pending capital voucher, and can take a deposit back while the group is still Open.
  - **Start group** (needs `investments.manage`) freezes the members and creates **Project 1** (code `G1-P01`) starting on the chosen date, ending two calendar months later, with every member's amount copied into it. The profit maths, settlement, payouts and batches are unchanged: they still read the project's investments.
  - The money is booked **once**, when the member joins the group. The copies in a project book no second voucher.
  - Projects show their group; the project list has a Group column and filter. A group project's investment rows cannot be typed in, edited or deleted by hand; money goes into the group.
  - **Close group** needs a reason, every project settled and every member out.
  - Existing projects are moved into groups by one command, `php artisan investments:backfill-groups` (**always run `--dry-run` first**): project 1 → G1, project 2 → G2 …, each becoming Project 1 of its group. Nothing is deleted or recalculated, no voucher is booked, project codes are not renamed and automatic cycles stay off.
- **Investor Groups, step 2 of 6: the automatic 2-month cycle.** A group with **Automatic cycles** switched on (Running groups only) now closes its project when the project's two calendar months are over and starts the next one the same day, with the group's active members copied in. A member who left is not copied again. The finished project is ready for **Calculate & Settle** while the next one is already running.
  - The dates are counted from the day the group went Running, in calendar months, so a group started on the 31st closes on 31 Oct, 31 Dec, 28 Feb, 30 Apr … without drifting.
  - It never starts two projects for one date. If whole cycles were missed (the server was off, or automatic cycles were switched on late) it does **not** catch up on its own: the group page shows "Behind by N cycle(s)" and staff confirm with **Start the next project now**, which starts exactly one project per confirmation.
  - New command `php artisan investments:advance-groups [--company=] [--dry-run]`, scheduled hourly (each company's own midnight is used). Groups migrated from old projects start with Automatic cycles **off**; staff switch it on after checking the dates.
  - **New warning before settling:** when the capital base (investors + company contribution) is not the landed cost, the project page shows how far apart they are and why that changes the profit per lakh. It never blocks settling.
- **Investor Groups, step 3 of 6: new money and leaving.**
  - **New money goes into an open group.** A portal top-up, a payout the investor reinvests, and an investor imported or signed up through Investor Drafts now join an **open** group as a new member (the usual pending capital voucher is booked once). They never join a group that has started. If the group an investor asked for has started by the time staff approve, staff pick another open group in the approval box. Projects that are not in a group still take money the old way.
  - **Leaving a group.** A withdrawal request or notice is about a group. After the 60-day notice, the member leaves at the **first project end after the notice runs out** (a notice that ends exactly on the project's end date leaves that day). They are not copied into the next project, and the cycle marks the notice honored. Everyone else stays.
  - **What a group project pays.** Members who stay keep their principal in the group, so their payout is **the profit only**; a member who leaves gets **principal + profit**. The profit maths (pool, rate per lakh, annualized return) are unchanged. Projects outside a group still pay principal + profit as before. *Owner to confirm this rule: see the release note below.*
  - A loss that the **investors** bear cannot be settled on a group project yet (it would reduce the stake of members who stay, and that rule is not decided). "Company bears the loss" works.
  - The stamp and cheque numbers typed with an investor draft are kept on the member and become the security instrument of the member's first project row, once.
- **Investor Groups, step 4 of 6: the payment screenshot.** When staff pay by hand, they can now upload a **screenshot or slip** (image or PDF) of the payment: in the **Mark as Paid** box, or later with **Payment screenshot** on a paid payout; the same for channel-partner commissions and for the payouts of a payout batch. A newer upload replaces the one shown (the older file stays on record). The person paid sees it in the portal: a **Payment screenshot** button on their Payouts (investors) or Commissions (partners) page, and the file in their Documents list. Nobody else can open it, and a payout that is not paid yet cannot take one.
- **Investor Groups, step 5 of 6: the investor portal.**
  - **Dashboard → My investments** now shows each group the investor is in (code, name, how much of their money is in it, since when) with **a card per project**: a **progress bar** with the end date while the project runs; the bar full and **"Project N completed"** when its two months are over; then the profit, "You receive" and **"Payment on the way"**; then **"Paid"**. A project card **leaves the dashboard once its payout is paid and the payment screenshot is uploaded**; the full result stays on the **Payouts** page for good. A group that has not started shows "This group has not started yet".
  - **The totals are right for groups.** A member's money is counted **once** (each project holds a copy of it): *Capital at work* is the money in running groups, plus a leaving member's principal until their last payout is paid, plus projects outside any group; *Total invested* is one deposit per membership; the **Statement** lists one deposit per membership, so deposit + profit − payouts equals the principal still in the group. A channel partner's "capital at work" for their investors uses the same rule.
  - **Payouts** shows **Capital returned** ("Stays invested" for a member who stays) and the group and project number; **My investments** names the group and calls a finished cycle **Completed**.
  - A withdrawn row is no longer shown as a card on the dashboard (it stays on My investments and the statement).
- **Investor Groups, step 6 of 6: the "project completed" SMS.** When a group project's two months are over, every investor in it can get an SMS. The wording is the owner's, so it starts **empty and nothing is sent** until it is written: **Investor Portal Settings → Messages → Project completed message** (use `:name`, `:group` and `:project`). It goes out through the company's existing SMS gateway; one message per investor per project; numbers on the opt-out list are skipped; a gateway problem never stops the cycle. Every completion is written to the audit log (how many investors, how many SMS sent). Until step 3, **do not run the backfill on real data**: the top-up, reinvest and import screens still pick a single project, and they skip group projects.

**Technical Notes:**
- Migration `2026_10_12_100000_create_investor_groups_tables` (additive): `investor_groups`, `investor_group_members`, `investment_projects.group_id` / `sequence_in_group` (unique per group), `investments.group_member_id`. Index names are explicit (MySQL 64-character limit).
- `InvestorGroup` / `InvestorGroupMember` use `BelongsToCompany` and are in `MultiCompanyIsolationTest`, the audit-observer list and `User::MODEL_MODULES`. `InvestorGroupService` (add member, start, close, create project, snapshot), `GroupCycleCalendar` (calendar-month dates counted from the anchor with no-overflow, so a 31st anchor never drifts), `InvestorGroupBackfillService`, `InvestmentWithdrawalService::withdrawGroupMember()`.
- `Investment` refuses a row on a group project unless it points at a member of that group and investor. The pick-a-project flows (investor draft, import, portal top-up, reinvest) skip group projects (`InvestmentProject::ungrouped()`).
- Step 2: `InvestorGroupCycleService` (`decide()` is read-only, `advance()` does one step in a transaction and dispatches `InvestorGroupProjectCompleted` after the commit; a failing listener is reported, never undoes the cycle), `AdvanceInvestorGroups` command (per company, company timezone, `ModuleGuard`), `InvestmentProject::capitalBaseGap()`, schedule entry in `bootstrap/app.php` (`hourly`, `withoutOverlapping`, `onOneServer`). `InvestorGroup` refuses Automatic cycles unless the group is Running. 13 more lang keys (en + bn).
- Step 3: migration `2026_10_12_110000_add_group_targets_to_investor_flows` (additive): `group_id` on `investor_requests`, `investor_drafts`, `investment_withdrawal_notices`; `next_group_id` / `reinvestment_member_id` on `investor_cycle_elections`; `paperwork` / `leaves_after_sequence` on `investor_group_members`; `settlement_payouts.principal_returned_amount` (NULL = whole principal, every payout made before groups). `InvestmentTargets` is the one place that lists open groups and places money (`g<id>` / `p<id>` keys). `SettlementService` computes `total_payout = principal returned + profit` (group project: principal only for members with `leaves_after_sequence` = this project). `InvestorGroupCycleService::maturedNotices()` / notices applied before the next snapshot, also by the dry run and the confirmed catch-up. 23 more lang keys (en + bn).
- Step 4: the screenshot is an `InvestmentDocument` of the new category `payment_proof` attached to the payout (`HasPaymentProof` on `SettlementPayout` / `ChannelPartnerPayout`), kept in the company's private storage and served only by the existing admin download (`investments.view`) and the portal download (`InvestorPortalService::documents()` now also lists the proofs of the person's own payouts, nothing else of a payout). `PayoutProofService`, `PayoutProofAction` (shared field / action / column). No migration. 9 more lang keys (en + bn).
- Step 6: migration `2026_10_12_120000_add_project_completed_message_to_companies` (`companies.investor_project_completed_message`, NULL = off); listener `SendProjectCompletedMessages` (auto-discovered, on `InvestorGroupProjectCompleted`) sends through `StorefrontNotificationService::sendSms` with a 30-day per-investor guard. 4 more lang keys (en + bn).
- Step 5: `InvestorPortalCards` (cards: state, progress via `GroupCycleCalendar::progressPercent`, done rule), `InvestorPortalService::capitalAtWork()` / `workingCapital()` / `totalInvested()` / membership-based `statement()` / `partnerSummary()`, partial `filament/investor/pages/partials/project-card.blade.php`. 18 more lang keys (en + bn). Run `npm run build` on deploy (new utility classes in the portal views). Checked in a browser on a disposable database (desktop and phone widths).
- Tests: `ProjectCompletedMessageTest` (9), `InvestorPortalGroupViewTest` (14), `PayoutPaymentProofTest` (12), `InvestorGroupMoneyFlowsTest` (18) and `InvestorGroupExitTest` (11: notice on/after the boundary, several deposits, everyone leaves, the signed-invoice payouts with a leaver ৳2,077,486.16 and ৳7,748.62 for a stayer, profit-only next project, old projects unchanged, voucher amount of the payout), plus `GroupCycleCalendarTest` (12), `InvestorGroupCycleTest` (22: boundary days, 31st anchor, leap-safe February, missed cycles, one project per confirmation, withdrawn member skipped, settled project untouched, per-company and timezone, scheduler entry), `InvestorGroupTest` (32; includes the signed-invoice figures: pool ৳2,76,005.20, ৳3,874.31 per lakh, ৳7,748.62 for ৳2 lakh, 23.25% a year, and a group project settling exactly like an old-style project). 77 new lang keys (en + bn).
- Maintenance: added `16_INVESTOR_GROUPS_PLAN.md`, the approved plan for group-based investment (investors join groups, each group runs a new project every calendar 2 months, payment screenshot on payouts, portal progress bar). Planning document only; no code or data changes.
## [2.73.0] - 2026-10-12

**Release type:** Minor Feature Update

### Added

- **Reseller portal: product details.** A reseller reported that their dashboard did not show the product details and images. On **Products**, a new **Details** button (also opened by clicking a row) shows a slide-over with a large photo gallery, the product video, the description (with a **Copy description** button for posting), brand, SKU, category, unit, weight, stock, minimum order quantity and the variations with their own photos, price and stock, plus the reseller's own cost, suggested price, own price and commission per unit. It shows only that reseller's own figures: another reseller's price or store is never visible, and the company's purchase cost and wholesale tiers are not shown.
- **Reseller portal: bigger list photos and a fuller photo download.** The Products list thumbnail is larger and falls back to the first gallery photo when there is no main image. **Download photos** now also includes the photos of the product's variations, and the button shows whenever any photo exists. **Create order** shows the chosen product's photo, name, SKU and brand under the product field.
- **Reseller portal: a modern front page.** The Dashboard is redesigned. A welcome banner in the store's own colour shows the reseller's name and the money they can withdraw now, with **Withdraw** and **Create order** buttons and their pending, in-process and already-withdrawn amounts. Below it: one-tap shortcuts (create an order, add products, withdraw, share the store link with the phone's share sheet); four overview cards (parcels waiting, this month's orders, sales and commission) with a ▲/▼ percentage against the same days of last month and a 14-day trend line; a store card where the link looks like a browser address bar (copy, visit, share on WhatsApp / Facebook) with a QR code to scan and a "Live" or "Setting up your store address" badge; an **Order pipeline** (Confirmed, Processing, Shipping, Completed, plus Cancelled and Returned) where each step opens the Orders page already filtered to that stage; recent orders with the first product's photo, a coloured status badge and the amount; and the commission chart as a smooth filled line. It works on phones and in dark mode, and every number is only the signed-in reseller's own.
- **Reseller portal: filter Orders by stage.** `/portal/orders?stage=shipping` (and confirmed, processing, completed, cancelled, returned) lists only that stage, with a banner and a **Show all orders** link. An unknown stage in the address is ignored.
- **Reseller portal wears the store's colour and a Bangla-friendly font.** The portal's buttons, links and highlights now use the company's **Storefront Settings → theme colour** (the colour its reseller stores already use) instead of Filament's default amber, including the sign-in page; a company with no storefront settings keeps amber. Text uses **Hind Siliguri** (SIL Open Font License), self-hosted, which reads well in Bangla and English together. Cards, tables and the sidebar have softer rounded corners and the top bar is frosted.

### Fixed

- **Reseller portal: order status labels were not translated.** The status badges on the Orders page and the dashboard showed English even in Bangla; they now go through the translations (new keys **Confirmed** and **Shipping**).

### Technical Notes

- Product details: `Products::detailsFor()` builds the slide-over data (partial `filament.reseller.pages.partials.product-details`) from the table's own company-scoped, active+available query and `$this->picks` (this reseller's rows only). New `Product::allImagePaths()` (main + gallery + active variation photos, no repeats) feeds the zip (`ResellerProductPhotosController`) and the button. `CreateOrder::productPreview()`. 15 new lang keys (en + bn). `ResellerPortalProductsTest` +6 (details content, hidden cost/tiers, variations, thumbnail fallback, two-reseller isolation, zip with variation photos), `ResellerPortalCreateOrderTest` +1. No migration.
- Dashboard redesign: `Dashboard::kpis()` (this month to date against the same days of last month, 14-day series, from one orders query and one commissions query for this reseller only), `pipeline()` (one grouped query, stage decided by `Order::workflowStage()`), `recentOrders()` / `orderSummary()` (first product's photo through `CompanyMedia::filamentPublicUrl`), `greeting()` (company time zone), `storeQrCode()` / `subdomainIsSettingUp()`. The old `thisMonth*()` helpers were folded into `kpis()`. New partial `filament.reseller.pages.partials.sparkline`. `CommissionTrend` is now a filled `line` chart with no hard-coded colour, so it follows the brand colour.
- Stage filter: `Order::scopeInWorkflowStage()` mirrors `workflowStage()` in SQL (new `Order::SHIPPING_DELIVERY_STATUSES`, also used by `workflowStage()`); `Orders` has a `#[Url] $stage` property with `activeStage()` / `clearStage()`.
- QR code: `App\Support\QrCodeSvg` draws an inline SVG data URI on the server with `chillerlan/php-qrcode`, which `filament/filament` already requires (not a new package, but not a direct `composer.json` dependency either); it returns null on any failure so the page just hides the QR. No third-party QR service sees the store link.
- Theme: `ResellerPanelProvider::brandColorStyle()` (HEAD_END hook inside `ModuleGuard::run`) writes `--primary-50..950` from `storefrontSetting->normalizedThemeColor()` through the existing `DynamicColorService`, and `->font('Hind Siliguri', provider: LocalFontProvider::class)` names the face; `@font-face` rules and the rounded/frosted polish live in `resources/css/filament/reseller/theme.css`. Font files and `OFL.txt` are in `resources/fonts/hind-siliguri/` (400/500/600/700, Bangla + Latin subsets). Run `npm run build` on deploy.
- Tests: new `ResellerPortalDashboardRedesignTest` (18 tests: month comparison, no-percentage case, two resellers in one company and a reseller of another company, pipeline, the SQL stage scope against `workflowStage()` for 42 status/courier combinations, the stage filter and its isolation, front page content, subdomain badge, greeting by time zone, order rows, chart, brand colour per company and its default, QR helper, sparkline).
## [2.72.0] - 2026-10-11

**Release type:** Minor Feature Update

### Added

- **A title and description for every public page.** Until now every storefront page, including every product, showed the home page's title and description in browser tabs, Google results and link previews. Now a product page reads "Steel Bolt | Your Store" with the start of the product description; a category, a custom page, an offer and the contact page do the same, using the page's own meta title and meta description where the admin entered them. The product and category photo, and the page or offer cover, become the share picture. The home page keeps the site title.
- **Canonical address on every indexable page.** Each page names its own address without tracking parameters (`?utm_source=...`), so a shared or advertised link does not split the page into duplicates in search results.
- **`robots.txt` per domain.** A storefront domain now tells crawlers to skip the cart, checkout, account, order tracking and complaint pages, and names its sitemap. The app's own domain keeps the admin-only rules.
- **`sitemap.xml` per storefront domain.** It lists the home page, the shop, the contact page, categories that have products, available products (with their last-changed date), published pages and published offer landing pages. Draft pages and offers, coming-soon and inactive products and empty categories are not listed. A reseller's own store has no sitemap, because it repeats the company's catalog at other prices. The list is cached for 10 minutes and refreshed when a product or category changes; a catalog over 45,000 addresses is split behind a sitemap index.
- **Structured data (JSON-LD) so results can show price and stock.** A product page carries the product's name, photos, SKU, brand, price (or the cheapest and dearest variant), currency and stock (in stock, pre-order, out of stock); a product with no price publishes no price. Product and category pages carry a breadcrumb trail. The home page carries the organisation (name, logo, phone, email and the social profiles the admin entered) and the site search. No star rating is published, because the product page shows no reviews. A reseller's own store publishes no price markup.
- **Share previews know the site.** Pages now send the site name, the page address and the language (`en_US` or `bn_BD`) to Facebook and other link previews, and a product page is typed as a product.

### Changed

- **Only the plain shop, category and offer lists are indexed.** Search results, sorted, filtered and later pages, and the single/combo filter on the offers page tell search engines not to index them (`noindex, follow`) in every theme; before, only Marketplace Pro did this, and search results were not covered.
- **Private pages are never indexed.** A page is kept out of search results unless its controller says it may be indexed, so the cart, checkout, order tracking, account, complaint and every new page start out private.
- The default meta description ("Shop products from ...") is now translated.

### Technical Notes

- New `App\Support\StorefrontSeo` (kept on the request, so one request never inherits another's values), `StorefrontSchema` (JSON-LD builders) and `StorefrontSitemap`; new `Storefront\SeoFilesController` for `/robots.txt`, `/sitemap.xml` and `/sitemap-{n}.xml`, behind `ResolveCompanyFromDomain:optional`. The storefront layout prints the tags; `HomeController`, `ProductIndexController`, `ProductShowController`, `PageController`, `OfferController` and `ContactController` fill them in once for every theme.
- `public/robots.txt` is deleted and the `location = /robots.txt` block is removed from `nginx.template.conf`: the route answers instead. A static file would shadow the route, and that nginx block (no fallback) would turn the missing file into a 404 that crawlers read as "no rules" even though Laravel produced the body. `SecurityHardeningTest` now fails if either comes back. The robots rules for the admin (`/admin`, `/livewire`) are unchanged.
- `StorefrontListingCache::sitemap()` reuses the per-company generation that product and category saves already bump.
- The Marketplace Pro listing no longer pushes its own robots tag.
- New `StorefrontSeoTest` (24 tests) and 2 reseller-host tests in `ResellerSubdomainTest`. 2 new lang keys ("Shop products from :name", "Contact us").
- Not done: `hreflang` (visitors cannot switch language by address yet, so Bangla and English share one address), product and category meta fields in the admin (the title and description come from the product or category itself), and a canonical host for `www` against the bare domain (set a redirect on the web server).
## [2.71.0] - 2026-10-11

**Release type:** Minor Feature Update

### Added

- **Weight field on product variations.** In the product form, under Variations, each variation now has a "Variation weight (kg)" field. Leave it empty and the variation uses the product's weight. A filled weight is used for:
  - the storefront delivery charge (weight-based) on the Blade checkout, the website checkout and landing-page offers;
  - the Weight column on the printed invoice and PDF, and the item weight and Total Weight on the order page;
  - the website API (`variants[].weight_kg` on the product detail).
- **WooCommerce import keeps the variation weight and the description format.**
  - A variation's weight comes across with the variation. An empty weight in WooCommerce stays empty, so the variation uses the product weight.
  - The full description is imported as formatted text instead of plain text: headings, bold and italic, lists, links, tables, images, paragraphs and line breaks appear in the product form's description box and on the storefront as they were on the old site. Scripts and unsupported markup are removed.
  - Products imported before this change keep their plain-text description until the import is run again (`php artisan woocommerce:import-products {company-slug}`), which also refreshes their prices and weights from WooCommerce.

### Technical Notes

- New migration `2026_10_11_100000_add_weight_kg_to_product_variants` adds nullable `product_variants.weight_kg` (`decimal(8,3)`, same as `products.weight_kg`). Existing variations stay NULL, so nothing changes for them until a weight is entered.
- New `ProductVariant::effectiveWeightKg()` and `OrderItem::unitWeightKg()` (variation weight, else product weight). `StorefrontDeliveryService::quote()` reads the line's `variant` when present; `OfferCheckoutController` now passes the offer item's variation to it.
- 2 new lang keys in `en.json` and `bn.json`. New `ProductVariantWeightTest` (8 tests).
- New `App\Support\WooCommerceDescription` converts the WooCommerce description to the HTML the rich editor stores: it removes comments, scripts, styles and `[caption]` wrappers, adds the `<p>` and `<br>` that WordPress only adds on display (`wpautop`), then renders through Filament's `RichContentRenderer` (sanitised). Markup the editor cannot parse falls back to plain text instead of failing the import.
- `WooCommerceImportService` reads `weight` of each variation (kg, like the product weight; only a value above 0 is set). New `WooCommerceDescriptionTest` (7 tests); `WooCommerceImportTest` now expects the description as HTML and checks the variation weights.
## [2.70.0] - 2026-10-10

**Release type:** Minor Feature Update

### Added

- **Marketplace Pro product listing page.** The product list, category and search pages have their own Marketplace Pro design: breadcrumb, page title with product count, a filter sidebar on desktop and a slide-in filter panel on phones (categories, "In stock only", and a minimum/maximum price), a sort menu, and an empty state that suggests categories. Other themes keep their current listing page.
- **Stock and price filters on the product list.** `in_stock=1`, `min_price` and `max_price` in the address work without JavaScript and can be shared as a link. Filtered, sorted and later pages tell search engines not to index them (`noindex, follow`).
- **Marketplace Pro product page.** The page has:
  - a gallery with thumbnails, zoom on hover and the discount badge;
  - a buy box with the price, the struck-through original price, "Save ৳X", the VAT note, a stock pill (in stock / only N left / pre-order / out of stock) and the bulk pricing table;
  - variant rows with quantity steppers, "Add to cart" and "Buy now" buttons, and "Ask on WhatsApp";
  - delivery time and the admin trust items under the buy box;
  - the description, "You may also like" with Marketplace Pro cards, and a sticky buy bar above the bottom navigation on phones.

  The cart form, field names and routes are the same as before.
- **Marketplace Pro product card** on the home and listing pages: discount badge, stock badge, orange quick-add button, "Only N left", and the minimum order quantity. All text is in English and Bangla.
- **Marketplace Pro cart page.** Each cart row shows the photo, category, name, the chosen option, the price of one piece, a stock pill (in stock / only N left / pre-order), the minimum order quantity and the line total, with a quantity stepper, Update and Remove. For a product with wholesale prices it also says how many more pieces unlock the next price ("Add 6 more to pay ৳140 each"). The order summary shows the items, the subtotal, "Delivery: calculated at checkout" and the VAT note, with "Proceed to checkout" and "Continue shopping". On phones the subtotal and checkout button stay above the bottom navigation. The empty cart offers "Browse products". The update, remove and checkout routes are unchanged, and all text is in English and Bangla.

- **Marketplace Pro checkout page.** The checkout is one numbered flow: contact details, delivery address, payment method and an optional order note, with an order summary beside it (thumbnails, subtotal, VAT, parcel weight, delivery charge, delivery time and the total). Typing the address detects Inside or Outside Dhaka and updates the delivery charge, the delivery time and the total at once. The new-customer delivery advance note, the verified-advance note, the pre-order advance, the manual payment instructions (account number, sender number, transaction ID) and the online gateway note all appear as before, and ordering stays blocked when there is no usable payment path. On phones the live total and "Place order" stay above the bottom navigation. Every form field, field name and the checkout rules are unchanged, and all text is in English and Bangla.
- **Marketplace Pro order-success page.** After an order it shows the order number, a status ("Awaiting confirmation" until the team confirms), every item with its price and line total, the subtotal, discount, VAT, delivery charge, packaging fee, total, the amount paid and the amount due, the delivery details with the delivery time for the customer's area, the payment made at checkout (and whether it is still being verified), the WhatsApp group, the complaint link, "Track this order" and "Continue shopping". All text is in English and Bangla.
- **Marketplace Pro order-tracking page.** Customers type the order number and the phone used on the order (the same two-factor lookup as before; a link from the success page or a signed-in customer needs no phone). The found order shows a four-step progress bar (Order placed, Confirmed, On the way, Delivered) that follows the order and delivery status; a cancelled, returned or refunded order stops after the first step and says so. Below it: the delivery update with the courier name, tracking ID and booking status, the tracking updates as a timeline with the latest one marked, the items with their photos, and a summary with the subtotal, discount, VAT, delivery charge, packaging fee, total, amount paid and amount due, the delivery area and time, and buttons for a complaint, the helpline and more shopping. A reseller's customer still sees only the reseller's prices: item lines and one total, never the store's breakdown. All text is in English and Bangla.

### Fixed

- The struck-through old price on the shared product card showed the selling price instead of the original price.
- On the product page, each variant row showed the product's price instead of the variant's own price. The cart charges the variant price, so the page now shows that.
- On the shared cart page (every theme except Marketplace Pro), the amount at the end of each row was the price of one piece, so a line of 2 pieces at ৳150 showed ৳150 instead of ৳300. It now shows the line total.
- On the shared order-success page (every theme except Marketplace Pro), every item line, the subtotal, the delivery charge and the amount due all printed the order total. They now show the item's own line total, the real subtotal, the real delivery charge and the amount still due (VAT, discount and packaging fee rows appear when they apply), and the payment box shows the payment's own amount.
- On the checkout page (every theme), sending the form with an empty address and getting an error back left the delivery charge, delivery time and total blank, because the address reached the page's script as nothing instead of text. It is now always text.
- On the shared order-tracking page (every theme except Marketplace Pro), every item line printed the amount due of the whole order, so an order with two products showed the same amount twice. Each line now shows its own line total (or the reseller's price for a reseller's customer).
- The shared order-tracking page and its tracking-update lines were English only. They now go through the translation files, so they follow the visitor's language.

### Technical Notes

- New `App\Support\StorefrontListingFilters`, used by `ProductIndexController` and the preview listing. Filtered listings skip `StorefrontListingCache`. Price bounds are cast to `DECIMAL`, so SQLite compares them as numbers.
- New `StorefrontUrl::home()` helper and a `@stack('head')` in the storefront layout.
- The quick-add double-submit script moved to `storefront/partials/quick-add-script.blade.php`. The listing Meta Pixel events moved to `storefront/products/partials/listing-meta-events.blade.php`. Both are shared by every theme.
- New `App\\Support\\StorefrontProductPage` holds the purchase facts every theme's `products.show` shows: gallery URLs, variants, stock, MOQ, quantity bounds, tiers and discount.
- The product page script moved to `storefront/products/partials/product-page-script.blade.php`. Its button text comes from `data-count-label`, so it can be translated. The ViewContent pixel event moved to `product-meta-events.blade.php`.
- New views: `themes/marketplace-pro/products/show.blade.php`, `themes/marketplace-pro/partials/product-card.blade.php` and `themes/marketplace-pro/products/index.blade.php`. 51 new lang keys in `en.json` and `bn.json`.
- New `App\Support\StorefrontCartLine` holds what one cart row shows and allows: stock, pre-order, quantity bounds, photo URL, line total and the next wholesale step. The shared and Marketplace Pro `cart.show` both read it.
- The cart Meta Pixel ViewCart event and the double-submit script moved to `storefront/cart/partials/cart-meta-events.blade.php` and `cart-script.blade.php`, shared by every theme. New view `themes/marketplace-pro/cart/show.blade.php`; 20 more lang keys.
- New `App\Support\StorefrontCheckoutPage` (payment choices, the default one, whether ordering is blocked, delivery charges, the signed-in customer, the autosave URL) and `App\Support\StorefrontOrderSuccessPage` (the order's real amounts, the payment made, the links, the status label). The shared and Marketplace Pro `checkout.show` and `checkout.success` read them, so a theme only changes the design.
- The checkout page's Alpine state, form script (autosave, leave-page guard, double-submit lock) and Meta Pixel InitiateCheckout event, and the success page's Purchase event, moved to `storefront/checkout/partials/` and are shared by every theme. The submit buttons take their sending text from `data-pending-label`, and the script handles more than one button.
- New views `themes/marketplace-pro/checkout/show.blade.php` and `success.blade.php`; 56 more lang keys.
- New `StorefrontMarketplaceProPagesTest`.
- New `App\Support\StorefrontTrackPage` holds what the tracking page shows about a found order: the progress steps and their state, the order and delivery status labels, the courier parcel, the items with the buyer's prices (`showsResellerPricesToBuyer()`), the amounts and the delivery area and time. The shared and Marketplace Pro `track.show` both read it.
- `OrderTrackController::trackingUpdates()` now wraps its titles and messages in `__()`; the English text is unchanged. New view `themes/marketplace-pro/track/show.blade.php`; 44 more lang keys (including the courier status labels In Transit, Picked Up, Booked, Not Booked and Booking Pending).
- `StorefrontMarketplaceProPagesTest` now has 42 tests (9 new for tracking).
## [2.69.4] - 2026-10-10

**Release type:** Patch/Fix Update

### Changed

- **The SMS gateway moved from Storefront Settings to Settings → Integrations → SMS Gateway.** The URL template, API key and sender ID are now edited there, per site (use the Site picker, like Payment Gateway). Values already saved keep working — nothing needs to be re-entered. Saving Storefront Settings no longer touches the SMS keys.

### Technical Notes

- SMS keys (`sms_api_url`, `sms_api_key`, `sms_sender_id`; `StorefrontNotificationService::SMS_CREDENTIAL_KEYS`) still live in the encrypted `storefront_settings.notification_credentials`; no migration. `Integrations::save()` merges only these keys into the stored array, and `EditStorefrontSetting::mutateFormDataBeforeSave()` re-adds them so the Storefront Settings form (which no longer has the fields) cannot wipe them. Tests: `IntegrationsPageTest` (+2).
## [2.69.3] - 2026-10-10

**Release type:** Patch/Fix Update

### Fixed

- **Create/Edit Order: Total amount and Due amount now include the Shipping Fee as soon as you type it.** Before, the totals only recalculated when the field lost focus, so on a phone (where closing the keyboard leaves the field focused) a typed Shipping Fee of ৳110 never reached the Total amount, which kept showing only the products' ৳200. Shipping Fee, Discount, Vat, Paid amount and each item's Quantity and Unit price now update the totals about half a second after you stop typing.

**Technical Notes:**
- `OrderForm`: the six totals inputs changed from `live(onBlur: true)` to `live(debounce: OrderForm::TOTALS_DEBOUNCE_MS)` (500 ms). The saved order was never wrong (`OrderWorkflowService::sync()` recomputes it from items + fees after every save); only the on-screen total was stale. New tests in `OrderFormTest`: `test_shipping_fee_is_added_to_the_total_amount_on_the_live_create_form` and `test_inputs_feeding_the_order_totals_recalculate_while_typing_not_only_on_blur`. No migration, no new strings.
## [2.69.2] - 2026-10-10

**Release type:** Patch/Fix Update

### Fixed

- **Investor portal sign-in, sign-up and password-reset pages: the company logo is now a standard size** (4rem high) instead of the tiny sidebar size. The logo in the signed-in header is also a standard size now (2.75rem high instead of Filament's tiny 1.5rem).

**Technical Notes:**
- `brandLogoHeight('2.75rem')` on the investor panel; rule added to `resources/css/filament/investor/theme.css` (`.fi-simple-layout .fi-logo`); `!important` is needed because Filament sets the height inline.
## [2.69.1] - 2026-10-10

**Release type:** Patch/Fix Update

### Changed

- **Investor portal sign-in page:** the heading under the company logo now reads **Investor Dashboard**, so it is clear which sign-in this is.

**Technical Notes:**
- `Login::getHeading()`; 1 new lang key (en + bn); assertion added to `test_the_login_page_has_a_forgot_password_link`.
## [2.69.0] - 2026-10-10

**Release type:** Minor Feature Update

### Added

- **Investor portal: staff can set a password.** On an investor's or channel partner's page, **Portal access → Set a portal password** (needs `investments.portal_manage`) opens the portal account if there is none, sets the password and makes it active, so the person can sign in with their phone number and that password at once, without an SMS. Audited as `investor_portal.password_set_by_staff`; not offered for a disabled account.
- **Investor portal sign-in: "Forgot your password?" link** under the password field, opening the existing SMS-code reset page.

**Technical Notes:**
- `InvestorPortalAccountService::setPasswordByStaff()`; link added to `Login::form()`. New tests in `InvestorPortalTest`. 6 new lang keys (en + bn).
## [2.68.0] - 2026-10-10

**Release type:** Minor Feature Update

### Added

- **A reseller's new store subdomain sets itself up and shows when it will be live.** After a reseller picks a subdomain on **My Store**, the page shows "Setting up <name>.<domain> (DNS and SSL)" with a countdown, checks every 15 seconds whether the address already opens over HTTPS, and switches to "Your store address … is live." on its own. Nothing to do on the reseller's side and no per-reseller server work: the wildcard DNS record covers every name, and the SSL certificate comes from the wildcard certificate or, new, from an on-demand proxy, because `/internal/tls-ask` now also approves the subdomain of every approved reseller. Until the address is ready the reseller's shared store link stays the long `/store/<slug>` link, so nobody gets a link that does not open yet. The countdown length is **Resellers → Reseller Settings → Subdomain set-up time (minutes)** (Super Admin, default 10).
- **The name at the top of the reseller portal's user menu now opens the Profile page.**

### Technical Notes

- Subdomain set-up: migration `2026_10_10_100000_add_reseller_subdomain_status` (`customers.reseller_subdomain_changed_at` / `reseller_subdomain_live_at`, `storefront_settings.reseller_subdomain_setup_minutes` default 10). Subdomains chosen before it (no `changed_at`) count as live. `Customer::resellerSubdomainIsReady()` / `resellerSubdomainReadyAt()`; `resellerStoreUrl()` uses the subdomain only once it is ready. New `App\Services\ResellerSubdomainProbe` (DNS points at the company domain's IPs + an HTTPS request that succeeds), polled by `MyStore::checkSubdomain()`. `TlsAskController` also answers 200 for approved resellers' subdomains. Profile link: `ResellerPanelProvider` `userMenuItems(['profile' => ...->url(Profile::getUrl())])`. Tests: `ResellerSubdomainTest` +5, `ResellerPortalDashboardTest` +1, `ResellerSettingsPageTest` updated. 7 new lang keys (en + bn). `docs/deployment.md` updated.
## [2.67.0] - 2026-10-09

**Release type:** Minor Feature Update

### Added

- **WhatsApp Web Login (CRM → WhatsApp Web Login).** Link the WhatsApp Business app to the ERP by scanning a QR code, the same way as WhatsApp Web. Every 1:1 chat the phone shares — the full history and every new message, sent or received — is saved into the CRM Inbox with photos, voice notes, videos and documents, and every WhatsApp contact is saved as a customer record linked to the matching customer or lead by phone. The page shows the connection status, the QR code, history sync progress and how many chats, messages and contacts were saved. In English and Bangla.
- **Chats and customer info survive a ban.** The archive belongs to the ERP: logging out, deleting the channel or WhatsApp banning the number never deletes saved chats, messages or contacts. A "delete for everyone" on WhatsApp keeps the original message in the ERP and marks it **Deleted on WhatsApp** in the Inbox.

### Fixed

- **Connect WhatsApp App (Coexistence) could fail at the last step.** The page only accepted Meta's popup reply from `www.facebook.com`, so when Facebook opened as `web.facebook.com` (common in Bangladesh) the WhatsApp Business Account ID and phone number ID were lost and the connection failed with an unclear error. Any Facebook address is now accepted, the page waits a few seconds for Meta's reply, and if the IDs still do not arrive it says so clearly instead of trying to connect without them.

### Changed

- WhatsApp Web chats are read-only in the ERP Inbox — reply from the phone app (internal notes still work). The AI auto-reply, read receipts and SMS fallback never run for them.

### Security

- The bridge API key is stored encrypted per company; every webhook from the bridge is HMAC-SHA256 signed with a per-channel secret and rejected otherwise.

### Technical Notes

- **New service to deploy:** `whatsapp-bridge/` (Node.js 20+, Baileys `7.0.0-rc14`, Dockerfile included). It needs `BRIDGE_API_KEY` and a **persistent** `DATA_DIR` volume; run it as its own Coolify app. See `whatsapp-bridge/README.md`. Unofficial WhatsApp Web client — not a Meta API.
- Migration `2026_10_05_100000_add_whatsapp_web_archive_support`: `conversation_channels.web_status/web_phone/web_connected_at/web_disconnected_at/web_history_synced_at`, `conversation_messages.revoked_at`, new company-owned `whatsapp_contacts` table (channel_id nullOnDelete). `connection_type = 'web'` marks these channels.
- New routes `POST /webhooks/whatsapp-web/{channel}` and `/media` (CSRF-exempt, throttle 600/min). Messages are archived by queued `ImportWhatsAppWebMessagesJob` / `SyncWhatsAppWebContactsJob` (idempotent on `waweb:{channel}:{id}`), so a queue worker must run. Media uploads need PHP `post_max_size` large enough for the attachments.
- Rules for WhatsApp Web channels, with the relevant clauses of WhatsApp's Terms of Service, Business App terms, Messaging Guidelines and Business Messaging Policy quoted word for word (checked 2026-10-06), are in `docs/whatsapp-web-rules.md` and referenced from `CLAUDE.md`.
- New `WhatsAppWebArchiveTest` (12 tests) and bridge unit tests (`npm test` in `whatsapp-bridge/`, 10 tests).
## [2.66.1] - 2026-10-09

**Release type:** Patch/Fix Update

### Fixed

- **Investor portal: the profile in the top-right menu now opens the Profile page.** The name at the top of the user menu was plain text; it is now a link to the Profile page.

**Technical Notes:**
- `InvestorPanelProvider` sets a URL on the `profile` user-menu item. New test `test_the_user_menu_name_links_to_the_profile_page`.
## [2.66.0] - 2026-10-09

**Release type:** Minor Feature Update

### Added

- **One module's error no longer takes the whole app down** (owner request after the v2.63.0 outage). Errors are kept inside the part that failed:
  - **Sidebar counts:** if the number next to a menu item (Investor Requests, Payout Batches, Investor Drafts, Reseller Payout Requests, Inbox) cannot be worked out, that count simply does not show. The menu and every page keep working.
  - **Dashboard and page widgets:** a widget that fails shows a small "This section could not be loaded" card in its place. The rest of the dashboard or page works as normal. Shared parts drawn on every page (sidebar, company switcher, banners, toolbars) leave out only the part that failed.
  - **A crashed page** in the admin panel or a portal shows "This page could not be opened. The other sections keep working" with **Go to the dashboard**, **Try again** and **Go back** buttons, instead of the bare "500 Server Error". 403/404 pages and sign-in redirects are unchanged.
  - **Missing database table** (e.g. an update that did not finish): the pages of that one section show "This section is temporarily unavailable", and its tabs and buttons on other pages are hidden. Everything else keeps working. The section turns back on by itself as soon as the update finishes.
  - Every error caught this way is **reported to Super Admins** (bell notification titled "Module error …" and **Settings → Deploy Error Logs**), at most once an hour per error, so nothing is hidden silently.
- **New automatic check (GitHub Actions "MySQL migrations")** runs every database update against MySQL 8.4, the production version, on every branch push and pull request. A database update that would fail on the live server now shows up as a red check on its branch, before it reaches `main`.

### Technical Notes

- `App\Support\ModuleGuard::run($section, $callback, $fallback)` catches, logs and reports through `DeploymentErrorReporter` (source `module:<section>`, throttled 1/hour per section and error via `Cache::add`). `ModuleGuard::renderView()` renders a nested view and, on failure, restores the view factory's component/section/stack state that Laravel flushes, so the page around it survives. Isolation is on everywhere except `local` and `testing` (where errors still throw); override with `ISOLATE_MODULE_ERRORS` (`config('app.isolate_module_errors')`).
- Badges: trait `App\Filament\Concerns\HasGuardedNavigationBadge` (write the count in `navigationBadge()`). Widgets: trait `GuardsWidgetRendering` (all 19 widgets; the widget view is rendered inside the guard in `filament.widgets.guarded`). Render hooks: `App\Support\GuardedViewManager` replaces Filament's `ViewManager` (`AppServiceProvider::register()`, `extend()`), so every render hook of every panel is guarded. Its `renderHook()` copies Filament's; re-check it on a Filament upgrade.
- Crashed pages: `App\Support\PanelErrorPages` (first path segment `admin` / `investor` / `portal`; Livewire updates use the Referer) renders the self-contained `panel-errors.module` view from `bootstrap/app.php`'s exception renderer, only for real 500s.
- Missing tables: `App\Support\ModuleHealth` (scoped; table list cached 10 minutes and dropped on `MigrationsEnded`). Admin panel middleware `TurnOffPagesWithMissingTables` answers a resource page whose model table is missing with a 503 page. `Gate::before` denies every ability on such a model (models in `User::MODEL_MODULES`). Off in `local` / `testing`, like `ModuleGuard`.
- New `ModuleGuardTest` (12 tests). It enforces that every navigation badge and every widget in `app/` goes through the guard, and it reproduces the 2026-10-08 state (dropped `investor_requests` table). Without the guard that state answers 500; with it, `/admin` opens. 10 new lang keys (en + bn).
- New workflow `.github/workflows/mysql-migrations.yml` (MySQL 8.4 service, `migrate --force` from empty, then checks nothing is pending). Checked locally against MySQL 8.0: all 219 migrations run; the unfixed v2.63.0 agreement migration fails with error 1059 (exit 1), the same error as production; the fixed one also finishes a database left half-migrated by it.
## [2.65.0] - 2026-10-09

**Release type:** Minor Feature Update

### Added

- **Lead CRM sales agent: 5 opt-in intelligence upgrades (proactive mode, objection handling, multi-product cart, cross-sell, personalization).** Each is a per-company toggle in **Integrations → Auto Messaging**, off by default, so no existing company's agent behavior changes until an admin turns one on.
  - **Proactive follow-up questions.** After fully answering, the agent may also ask one natural next-step question (size/colour, quantity, delivery area or purchase timeframe) to help move the sale forward, instead of stopping at a bare answer.
  - **Mild price-objection handling.** A "can you reduce it a bit?" message no longer always hands off to a human. The agent may acknowledge it and restate real value from grounded facts — it can never invent, offer or apply a discount. Refunds, complaints and explicit human requests still always hand off.
  - **Multi-product cart.** The customer can add more than one product to the same checkout link across the conversation ("I want 2 shirts" ... later "add a lamp too"), instead of each request for create_order_link replacing the previous one.
  - **Grounded cross-sell / upsell.** The agent may suggest one real, same-category related product alongside its answer — never invented, never offered on every message.
  - **Personalization by purchase history.** The agent can greet a returning customer and acknowledge past orders instead of treating every conversation as a first-time visitor.

### Technical Notes

- `AiSettingsService::DEFAULTS` gains `proactive_mode_enabled`, `objection_handling_enabled`, `cross_sell_enabled`, `personalization_enabled` (all `false`), wired through `Integrations.php`'s existing per-tool fill-state/fields/save plumbing.
- `AiReplyService::systemPrompt()` builds each rule as a ternary on its setting, defaulting to the exact prior rule text when off so the default prompt is byte-for-byte unchanged; two purely additive rules (cross-sell, personalization) are empty strings when off.
- `AiReplyService::HANDOFF_KEYWORDS` split: refund/complaint/human-request keywords always hard-escalate; a new `OBJECTION_KEYWORDS` (discount/bargaining) escalates too unless `objection_handling_enabled`.
- `AiReplyService::toolDefinitions()` now takes `$settings` — `lookup_related_products` is only appended to the tools array (not just discouraged by prompt text) when `cross_sell_enabled`, so the model cannot call a tool that was never offered.
- `create_order_link`'s schema changed from flat `product_id`/`variant_id`/`quantity` to an `items` array; `AiReplyService::createOrderLink()` merges newly-requested items into the conversation's existing open `ChatOrderLink` (same product+variant updates its quantity, otherwise appends a line) instead of always creating/overwriting a single-item link. The storefront checkout (`ChatOrderController`) already looped over `prefill.items`, so no checkout-side change was needed.
- `lookup_related_products` reuses the exact same-category/active/in-stock signal `ProductShowController` already uses for the storefront's own "related products", rather than inventing a new one.
- `SalesReplyRenderer::render()` gains an `$allowFollowUpPrompt` parameter (default `false`): off, a `prompt_key` only renders when it's the only thing being said (unchanged); on, it can be appended after a verified answer. Also gains a `related` reply-key type.
- `SalesQualificationService::context()` gains an `$includePersonalization` parameter (default `false`): off, `recent_orders` keeps its exact original `order_number`/`status`-only shape; on, it eager-loads each order's product names and adds `is_returning_customer`/`lead_source`.

## [2.64.1] - 2026-10-08

**Release type:** Hotfix Update

### Fixed

- **Admin panel showing "500 Server Error" after the v2.63.0 deploy.** On the live MySQL database the migration `2026_10_08_100200_create_agreement_tables` failed. The unique index on `agreement_acceptances` got an automatic name 68 characters long, and MySQL allows at most 64. The deploy's migration stopped halfway: `agreement_templates` and `agreement_acceptances` were created, but the migration was not recorded, and the later `investor_requests` and `payout_batches` tables were never created. The sidebar counts for **Investor Requests** and **Payout Batches** query those tables on every admin page, so every page answered 500. Each later deploy then failed again with "table already exists".

### Technical Notes

- The unique index now has a short explicit name (`agreement_acceptances_template_account_unique`). The migration skips any table that already exists and only adds the missing index, so the next deploy's `deploy:migrate` finishes it on a half-migrated database and then runs `2026_10_08_100300` / `100400`. No data is changed.
- New `MigrationIdentifierLengthTest` fails when any table or index name is over 64 characters. The test suite runs on SQLite, which has no such limit, so this class of error used to show up only in production.
- The same test found two older index names over the limit: `broadcast_recipients` (68) in `2026_08_28_000300` and `investment_documents` (71) in `2026_09_07_100200`. Both now have explicit short names, so a fresh MySQL install works. On production those migrations failed the same way earlier and were finished by `hasTable` guards (CHANGELOG: "Duplicate column name 'opted_out_at'"), so production has the tables but **not** these two indexes: the duplicate guard on broadcast recipients and a lookup index on documents. That is not urgent and is not fixed here.
## [2.64.0] - 2026-10-08

**Release type:** Minor Feature Update

### Added

- **"Open investor portal" button on an investor's or channel partner's page.** One click opens the investor portal in a **new tab**, already signed in as that person, so staff can see exactly what the investor sees without asking for a password. It is look-only: staff can see everything but change nothing (they cannot accept an agreement, add a payment account, send or cancel a request, or change a password), the visit is **not** counted as the person signing in, and the audit log shows the staff member as the one who opened it. It works even before the person has set a password. Needs **Investments: Manage Investor Portal Access** (Super Admin by default) and the portal switched on. A yellow "Staff preview" bar at the top of the portal reminds staff whose portal they are in. If the person has no portal account yet, one is created (no SMS is sent; it shows as "Invited" until a real invite is sent). A person whose portal access is disabled, or whose phone number has a portal application waiting for review, cannot be opened.

**Technical Notes:**
- Staff preview: the dashboard and the portal are on different hosts, so `Admin\InvestorPortalOpenController` (`GET /admin/investor-portal/{investors|channel-partners}/{id}/open`, `auth`, `investments.portal_manage`, same company check as the document downloads) issues a one-time token through `InvestorPortalStaffEntryService` (60 seconds, only its hash is cached, works once, only on the issuing company's portal, re-checks that the staff member is still active and allowed) and redirects to `<portal host>/investor/staff-entry/{token}` (`InvestorPortalStaffEntryController`, throttled). That logs the account in on the `investor` guard without "remember me" and sets `InvestorPortalStaffPreview::SESSION_KEY` in the portal session only. `InvestorPortalAccount::canAccessPanel()` lets a staff preview in with no password set, never a disabled account. `AuditLogService` attributes what a preview records (`investor_portal.staff_entry_issued`, `investor_portal.staff_entered`, document downloads) to the staff user (`user_id`), not to the investor (`actor_*`). `InvestorPortalAccountService::ensureAccount()` is the account creation shared with `invite()`. The token lives in the cache, so the dashboard and portal must share a cache store (they do: same app).
- Look-only rule for portal pages: anything that changes data calls `refuseInStaffPreview()` (trait `InvestorPortalPage`) and hides its button while `isStaffPreview()`. Done for `Profile::save`, `Agreements::accept`, `BankAccounts` add and `Requests` new / cancel; every new portal page that writes must do the same. No migration. 7 new lang keys (en + bn).
## [2.63.0] - 2026-10-08

**Release type:** Minor Feature Update

### Added

- **Investor portal: sign-up through a channel partner's invite link.** On a channel partner's page, **Portal access → Turn on invite link** gives the partner a personal sign-up link (shown in their portal under **Invite investors**, with Copy and WhatsApp share). A new investor applies with it: their details, nominee, NID copy and photo, and a password. The application lands in **Investor Drafts** (source "Investor portal sign-up") with the uploaded documents; **Confirm** creates the investor and opens their portal account (the documents move to the investor), **Reject application** turns it down with a reason the applicant sees when they sign in. While waiting, the applicant sees only their application status. Money, the deposit and the signed deed are still handled at the office.
- **Payment accounts.** Investors and partners add a bank account (with 9-digit BEFTN routing number) or bKash/Nagad/Rocket number in the portal; it waits for approval on their page's new **Payment accounts** tab (approve / reject with reason; staff can also add one, approved at once). The approved account becomes the one payments go to; an approved account is never edited — a change is a new account. Account numbers are stored encrypted.
- **Portal agreements.** **Investments → Portal agreements**: staff write the investor agreement and the channel partner agreement, then publish them. A published version cannot be changed (a change is a new version, which everyone is asked to accept again). In the portal, **Agreements** shows the text and records "I have read and agree" with time, IP, device and a fingerprint of the exact text. This sits beside the signed paper deed, which stays the contract of record.
- **Investor requests.** In the portal, **Requests → New request**: add money to an open project (top-up), a withdrawal notice, what to do with an unpaid payout (withdraw / reinvest / partial reinvest), or a question. **Investments → Investor Requests** (badge = pending) lets staff approve or reject with a note the investor sees. Approving runs the existing flow: a top-up adds the investment and its pending capital voucher (only after the money has arrived), a withdrawal records the 60-day notice from the day the investor sent it, a payout choice records the cycle election (needs the settle permission). One pending request per kind and project/payout.
- **Payout batches.** **Investments → Payout Batches → New payout batch** picks the pending investor and channel-partner payouts of confirmed settlements and sends each to the payee's approved payment account (payouts without one, or with a reinvest choice, are listed as left out). A **different user** approves the batch (even a Super Admin cannot approve their own). Then **Make payment list** produces a CSV of payee, bank, branch, routing number, account number / mobile number and amount to pay from the bank or by hand; the file is stored, fingerprinted (SHA-256) and every download is logged. Payments that bounced are marked **failed** with a reason (the payout is free for a new batch); **Record as paid** marks the rest paid with the bank reference and books **one** pending investor-payout voucher for the batch. Trashing that voucher makes the payouts unpaid again, as before. A batch can be cancelled until it is recorded paid. New permissions **Investments: Make Payout Batches** and **Investments: Approve Payout Batches** (Super Admin only by default). The NCC Bank BEFTN upload file is not built yet: it waits for the bank's own file template.

### Changed

- **Mark as Paid** on a settlement's investor or partner payout now refuses a payout that is in a live payout batch, and a reinvest can no longer be recorded on such a payout (take it out of the batch first).
- The audit log no longer copies a record's hidden fields (such as encrypted account numbers) into its old/new values. Payment accounts, investor requests, portal agreements and payout batches are now recorded in the audit log.

### Technical Notes

- Plan: `15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md` Phases 3–4 (§15 build log). Migrations: `2026_10_08_100000_add_investor_portal_onboarding` (`channel_partners.invite_code` / `invite_enabled`, `investor_drafts.portal_account_id`, `investor_portal_accounts.investor_draft_id` / `rejection_reason`), `2026_10_08_100100_create_investor_bank_accounts_table`, `2026_10_08_100200_create_agreement_tables` (`agreement_templates`, `agreement_acceptances`), `2026_10_08_100300_create_investor_requests_table`. Additive; nothing backfilled.
- New models `InvestorBankAccount`, `AgreementTemplate`, `AgreementAcceptance` (immutable), `InvestorRequest` (all company-scoped, in `User::MODEL_MODULES` as investments and in `MultiCompanyIsolationTest`). New services `InvestorOnboardingService` (sign-up → draft; `InvestorImportService::confirm()` calls `draftConfirmed()`; deleting a portal draft rejects the application), `InvestorBankAccountService`, `AgreementService`, `InvestorRequestService`. Portal accounts gain statuses `pending` / `rejected` (applicants may sign in to see their status only; new page role `holder`). New portal pages Register, Agreements, Payment accounts, Requests, Invite investors; new admin `AgreementTemplateResource`, `InvestorRequestResource`, `BankAccountsRelationManager` (investor and partner pages).
- `InvestmentDocument` gains the `investor_photo` category (sensitive) and no longer writes a portal user's id into `uploaded_by` (staff only).
- New `InvestorPortalOnboardingTest` (13 tests). 154 new lang keys (en + bn).
- Payout batches (Phase 5): migration `2026_10_08_100400_create_payout_batches_tables` (`payout_batches`, `payout_batch_items` — payable and payee morphs, a copy of the payment account with the number encrypted). New models `PayoutBatch`, `PayoutBatchItem` (company-scoped, in `User::MODEL_MODULES` and `MultiCompanyIsolationTest`), `PayoutBatchService` (create / remove item / approve / export / mark failed / complete / cancel; permission and maker ≠ checker checked in the service), `PayoutCompletionService` (shared by the single-row Mark as Paid and batches: payout status, voucher, settlement `paid_out`). `VoucherLinkedPayoutsService::voucherRestored()` skips a payout that has joined a new live batch. New `PayoutBatchResource` (list with the New batch modal, view with Approve / Make payment list / Record as paid / Cancel, items with Take out / Mark failed). The payment list is a CSV (UTF-8, formula-like cells neutralised) on the private disk under `payout-batches/{company}/`.
- New `PayoutBatchTest` (13 tests). 72 new lang keys (en + bn).

- **Investors list: project picker on the table toolbar.** On **Investments → Investors** there is now a **project dropdown** ("All projects" or one project) at the top left of the table. Picking a project shows only the investors who invested in it. It works through the same "Project" filter as the filter menu, so the filter badge, the "Active filters" chip and "Reset filters" all follow it. The existing search box is unchanged.

**Technical Notes:**
- `InvestorResource::projectFilterOptions()` feeds both the filter menu and the toolbar picker. `ListInvestors::setProjectFilter()` writes the chosen project into `tableFilters` / `tableDeferredFilters` (an id that is not one of the company's projects is ignored). The toolbar is `resources/views/filament/investors/list-toolbar.blade.php`, hooked on `TablesRenderHook::TOOLBAR_START` scoped to `ListInvestors` in `AdminPanelProvider`. No migration. 3 new `lang` keys (en + bn).
## [2.62.0] - 2026-10-07

**Release type:** Minor Feature Update

### Added

- **Investor portal (first part).** Investors and channel partners can now sign in to their own portal at `investor.<company domain>/investor` (or a custom domain) from a phone. An investor sees their investments with each project's status and result, a statement of every investment, profit or loss, payment and reinvestment (printable / save as PDF), their payouts with the printable payout report, and their documents (their own papers plus their project's contract and cost receipts). A channel partner sees the investors they brought in (by the name used on shared papers only, no phone or NID) and their commissions, paid and due. Profit only appears once staff confirm the settlement.
- Sign-in by phone + password, or phone + a 6-digit SMS code (when the company's SMS gateway is set up); "forgot password" by SMS code. Nobody can sign in until staff invite them: **Portal access → Invite to the investor portal** on an investor's or channel partner's page sends an SMS link (valid 7 days) to set a password. Staff can disable access (the person is signed out at once) and enable it again. One person who is both an investor and a partner gets one sign-in that shows both.
- **Investments → Investor Portal Settings:** turn the portal on or off and see its address; the custom domain is Super Admin only. New permission **Investments: Manage Investor Portal Access** (`investments.portal_manage`), held only by Super Admin by default.
- The Audit Log now shows actions done by portal users (investor portal and reseller portal), marked "(portal)".

### Technical Notes

- Plan: `15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md` (Phases 1–2 built; §15 lists the answers assumed and where the build differs from the plan). Phases 3–7 (self sign-up/KYC, requests, payout batches, notifications, period pool engine) wait for the owner's answers.
- Migrations: `2026_10_07_130000_add_investor_portal_to_companies_table` (`investor_portal_enabled` default false, `investor_portal_domain` unique), `2026_10_07_130100_create_investor_portal_accounts_table`, `2026_10_07_130200_add_actor_to_audit_logs_table` (nullable `actor_type` / `actor_id`). Additive; nothing is backfilled.
- New Filament panel `investor` (`InvestorPanelProvider`, guard `investor`, provider `investor_portal_accounts`, theme `resources/css/filament/investor/theme.css` — `npm run build` needed on deploy). New `InvestorPortalAccount` model (company-scoped), `InvestorPortalAccountService` (invite, password, SMS codes: hashed, 10 min, 5 tries, 1 send/min), `InvestorPortalService` (every portal query, pinned to the signed-in investor/partner), `ResolveCompanyFromInvestorPortalDomain`, `Company::forInvestorPortalHost()` / `investorPortalHost()` / `investorPortalUrl()`, portal download/report controllers with ownership checks (`InvestmentReportController::investorPayoutView()` is shared).
- `RestrictResellerPortalHost` renamed `RestrictPortalHosts` and now guards both portal hosts. `AuditLogService` writes non-staff actors to the new actor columns. "investor"/"investors" added to the reserved reseller subdomain labels; the investor portal domain counts as taken for reseller custom domains.
- New `InvestorPortalTest` (19 tests); `MultiCompanyIsolationTest` covers `InvestorPortalAccount`. 120 new lang keys (en + bn). DNS/Coolify steps: `docs/deployment.md` → Investor Portal Domain.
## [2.61.0] - 2026-10-07

**Release type:** Minor Feature Update

### Added

- **The Next.js site's home page, header and footer follow the approved design, and every part of it is customised from the dashboard (owner request).** A new **Website Design** tab on the Next.js site's row in Site → Storefront Settings (the Blade storefront has no such tab) holds:
  - **Home Page Builder** — add, remove, hide and reorder the home page sections: *Hero slider* (banner height, seconds per slide, dots, arrows), *Trust strip* (icon, title and subtitle for up to 6 highlights, 16 icons), *Shop by category* (heading, chosen categories in the order picked, how many, cards with icon and tagline or round pictures, product count), *Deals banner* (the gradient "Best deals today" banner with the biggest discounts, a collection or trending/new products, its own colours and link), *Product row* (latest, one category, biggest discounts, or a Flash Sale / Trending / New Arrivals collection; 1–24 products; 4, 5 or 6 columns; its own "View all"), *Flash sale band* (badge, heading, text, countdown, button, background colour — title, text and end time default to the Flash Sale offer's own), *Image banners* (upload up to 6 images with links), *Shop on mobile* (WhatsApp / call buttons and phone pictures with products), *Text block*, *SEO text with Read more* (supports `{company_name}`, `{address}`, `{products_count}`, `{categories_count}`) and the *Business account callout*. Until an owner saves their own order, the default layout is hero, trust strip, categories, deals, latest products, flash sale, trending and new arrivals. A section's heading left blank uses the website's own default heading; the default layout carries no copy of its own.
  - **Top Bar** (the thin dark strip: social icons, a short message, up to 6 quick links with small icons, background colour), **Header** (tagline, search hint, which icons to show, support label and phone, the "All categories" button and its label, sticky or not), **Product Cards** (price and discount-badge colours; show or hide the stock label, Add to cart, wishlist heart, quick view and star rating) and the **Floating Help Button** ("Need help?" — its text and link, else a WhatsApp chat with the WhatsApp number).
- **Footer Builder: build any footer you need.** Four new section types — **Custom links column** (a heading and links to pages, categories, tracking, the account or any URL — for example *Customer Service*), **Custom text** (heading, text, button), **Payment badges** (bKash, Nagad, Rocket, Cards … in a colour each) and **Image / badge** (an uploaded picture with a link, for example a trade licence). The **Contact info** section can now show an address, email, several phone numbers, the support hours and a trade-licence line (blank fields use the company's own details), **Brand & about** a tagline, and the **Bottom bar** a middle and a right-hand text (`{heart}` shows a red heart, as in "Developed with ♥ for Bangladesh"). Small sections (social links, text, payments, image) sit under the column before them unless **Show as its own column** is on, and the footer's background colour is chosen on the Next.js row. The new sections show on the Blade storefront's footer too.
- **Category tagline** — a short line (for example "Earbuds · TWS · Headsets") under a category's name on the Next.js site's category cards (Categories → Tagline). **Hero slide texts** — the slide form now offers the small label above the heading, heading, sub-heading and button label that the slide already supported; leave them empty for a clean image banner.
- **Dashboard edits reach the website within seconds.** Saving storefront settings (home page, header, footer, menus), a hero slide, a page, a category or an offer now sends a `storefront.updated` webhook to the company's website (about 5 seconds later, at most once per 5 seconds), so the Next.js site refreshes instead of waiting for its cache to expire. Nothing is sent when no website webhook is set up.
- **The product list can sort by biggest discount and show only products on sale** (used by the home page's "Biggest discounts" and "View all promotions"). A product card tells the website which live collections the product is in, which drives its "New" badge.

### Technical Notes

- Migration `2026_10_08_100000_add_site_design_to_storefront_settings_and_tagline_to_categories`: `storefront_settings.home_sections` and `site_design` (JSON, NULL = defaults), `categories.tagline` (80), `storefront_slides.eyebrow` (60). New `App\Support\StorefrontSiteDesign` cleans everything before it leaves the ERP (unknown section types dropped, text trimmed and cut, numbers clamped, colours `#RRGGBB` only, links only a path / http(s) / mailto: / tel: — never `javascript:`, `data:` or `//host`); `StorefrontSetting::homeSections()` / `siteDesign()` use it. `StorefrontSiteConfigService` adds `design` and `home_sections` to `GET /site` (category ids → slugs, banner / footer images resolved only inside the company's own storage, sections that would be empty dropped, SEO tokens filled) and cleans/resolves the footer blocks (custom links → slugs, contact blanks → the company's details). `GET /categories` adds `tagline`, `GET /slides` adds `eyebrow`, product cards add `collection_kinds`, `GET /products` takes `sort=discount` and `on_sale=1`, menu items carry `icon` (top bar). New concern `NotifiesWebsiteOfStorefrontChanges` (StorefrontSetting, StorefrontSlide, StorefrontPage, Category, Offer) → `WebsiteWebhookDispatcher::dispatchStorefrontUpdated()` (cache-debounced, queued with a 5 s delay); documented in `docs/api/website-integration.md`. Admin: `Filament\Resources\StorefrontSettings\Schemas\NextjsDesignSections` (new `nextjs_design` tab, Next.js row only; the Blade row never stores `home_sections` / `site_design`), the Footer Builder in `StorefrontSettingResource`, Blade partials `footer-blocks/{custom_links,custom_text,payment_methods,image}` plus tagline / contact / bottom-bar additions. Contract: `docs/api/storefront-api.md`.
- Tests: new `NextjsSiteDesignTest` (22: defaults, cleaning and clamping, slug / image resolution and company isolation, trust-strip fallback, SEO tokens, top bar links, footer blocks and fallbacks, tagline, discount sort, collection kinds, admin form opens with the effective layout and saves, Blade row has no tab and stores nothing, Blade footer renders the new blocks). About 200 new keys in `lang/en.json` and `lang/bn.json`.
- **The website (`zamzamint-new-website-theme`) must be deployed with this release:** it reads `design`, `home_sections`, the new footer blocks, `tagline`, `eyebrow` and `collection_kinds`; an older website simply ignores them.
## [2.60.4] - 2026-10-07

**Release type:** Patch/Fix Update

### Fixed

- **A rejected or cancelled payout voucher now makes the payout unpaid again.** Until now only moving the voucher to trash did this. When the voucher created by **Mark as Paid** is rejected or cancelled, the investor payout, channel-partner payout or reseller withdrawal goes back to **pending** (**approved** for reseller requests), and a settlement that was fully paid out goes back to **confirmed**. The payout can then be paid again with a new voucher. Restoring a rejected or cancelled voucher from trash doesn't mark the payout paid again.

**Technical Notes:**
- `VoucherLinkedPayoutsService::voucherVoided()` is called from `VoucherService::reject()` and `cancel()`, and from trash (`voucherTrashed()` calls it). No migration.
## [2.60.3] - 2026-10-07

**Release type:** Patch/Fix Update

### Changed

- **Orders from a reseller's online store are now invoiced in the reseller's name too (owner rule).** Until now only the orders a reseller placed from the reseller portal showed the reseller's store name and logo; an order a customer placed on the reseller's own store (`/store/<name>`, the reseller's subdomain or own domain) still printed the company's name, logo, contact details and the internal "Storefront checkout" note. Now every order that belongs to a reseller carries the reseller's store name and logo and none of the company's details, on the printed invoice, the bulk print, the PDF invoice, the courier cut-slip and the order summary card. Amounts are unchanged (the customer already paid the reseller's price). Orders without a reseller keep the company invoice.

### Technical Notes

- `Order::showsResellerBranding()` is now simply "the order has a reseller" (`reseller_customer_id` set — portal orders and reseller-store checkouts). New `ResellerCommissionTest::test_an_order_from_the_resellers_store_is_invoiced_in_the_resellers_name` (real checkout on `/store/<slug>`, then print and summary card); `ResellerOwnCourierOrderTest`'s company-invoice test now uses an order without a reseller. No migration, no new lang keys.

## [2.60.2] - 2026-10-07

**Release type:** Patch/Fix Update

### Fixed

- **Every reseller-portal order is now invoiced in the reseller's name (owner rule).** Orders a reseller placed from the portal for the store to deliver (cash on delivery) still printed the company's name, logo, email and address and the internal "Reseller portal order" note. Now every order from the reseller portal — store-delivered or own courier — shows the reseller's store name and logo and none of the company's contact details or the internal note, on the printed invoice, the "Print invoices" bulk print, the PDF invoice, the courier cut-slip and the order summary card. Amounts are unchanged: on a store-delivered order the customer still sees the reseller's price, the delivery charge and the due amount. Orders from other sources keep the company invoice.

### Technical Notes

- New `Order::showsResellerBranding()` (a reseller order whose `source` is `reseller_portal`, or any own-courier order) decides the invoice brand; `showsResellerPricesToBuyer()` (own courier only) still decides the prices. Used in `orders/partials/invoice.blade.php`, `orders/pdf.blade.php`, `OrderPdfController` (reseller logo) and `OrderSummaryCardService`. `ResellerOwnCourierOrderTest`: the store-delivered invoice test now expects the reseller's brand (print, bulk print, PDF, summary card), plus a new test that a non-portal order keeps the company invoice. No migration, no new lang keys.
## [2.60.1] - 2026-10-07

**Release type:** Security Update

### Security

- **Staff only see and edit the users of their own companies.** Before, the Users page listed every user in the system, so someone with "Users: Manage" could open and change (name, email, password, active status) a user who works only in another company. Now, apart from a Super Admin, the Users page shows only users who share at least one company with you (and yourself), and opening any other user shows "not found". A Super Admin still sees everyone.

### Technical Notes

- `User::canSeeUser()` and the `User::visibleTo()` query scope. `UserResource::getEloquentQuery()` applies the scope (list, view, edit, bulk actions, global search), and `Gate::before` denies every ability on a `User` the signed-in user can't see. `UserManagementEscalationTest` gains 3 tests (16 total); its test manager now shares the factory's default company unless a test gives it one.
## [2.60.0] - 2026-10-07

**Release type:** Minor Feature Update

### Added

- **Reseller guide page (owner request).** A public page on the reseller portal (`reseller.<company domain>/portal/guide`) that a new reseller reads before joining: how the program works in four steps, pricing and commission, withdrawals, their own store (subdomain / custom domain only when switched on), the two delivery options (the own-courier rules only when the store has a payment method), the store's own **rules and regulations**, and **Apply now** / **Sign in** buttons at the top, in the header and at the bottom. Modern layout with the store's colour, logo and dark mode, phone friendly, English and Bangla. Managed on **Resellers → Reseller Settings → Reseller guide page**: turn the page on or off, write the introduction, add, reorder and remove rules, and choose whether to show the minimum withdrawal and packaging charge (off by default; reseller costs are never shown). While the page is on, the storefront's **Become a reseller** links open it instead of the application form, and the portal's application form links to it; someone who has already applied still goes to the portal.

### Technical Notes

- Reseller guide: migration `2026_10_07_110000_add_reseller_guide_to_storefront_settings_table` adds `reseller_guide_enabled` (default on), `reseller_guide_intro`, `reseller_guide_rules` (JSON list of `{title, body}`) and `reseller_guide_show_figures` (default off) to the Blade storefront row. New `Reseller\ResellerGuideController` + `resources/views/reseller/guide.blade.php` at `GET /portal/guide` (route `reseller.portal.guide`, portal host only via `ResolveCompanyFromResellerPortalDomain` / `RestrictResellerPortalHost`; 404 when the page is off). New `Company::resellerJoinUrl()` used by the storefront layout, Marketplace Pro home and account profile; the header's "Reseller status" link for someone who already applied goes to the portal. `ResellerPanelProvider` adds the guide link above the application form (`AUTH_REGISTER_FORM_BEFORE`). 70 new lang keys. New `ResellerGuidePageTest` (5 tests).

## [2.59.1] - 2026-10-07

**Release type:** Security Update

### Security

- **Staff can only give access to their own companies.** Before, anyone with "Users: Manage" could give a user (themselves included) access to any company in the system. Now, apart from a Super Admin, the company list on a user's page shows only the companies the editor can access themselves, and only those can be added, removed or made the default. If a user also has access to companies the editor can't see, that access stays as it is. A Super Admin can still assign any company.

### Technical Notes

- `User::assignableCompanyIds()` (all active companies for a Super Admin, otherwise the user's own active companies) and `User::resolveCompanyAccessChange()`, used by `CreateUser`/`EditUser`. `UserForm` limits both company fields' options and their loaded values to the assignable companies. A request naming another company fails Filament's option validation; the target's other companies and their default are carried over on save. `UserManagementEscalationTest` gains 5 tests.
## [2.59.0] - 2026-10-07

**Release type:** Minor Feature Update

### Added

- **Hero slides and custom pages can be limited to one site (owner decision).** Each slide and page has a new **Show on** choice: *All sites* (what every existing slide and page keeps being) or one of the company's sites — the Blade storefront (reseller store) or the Next.js site (main storefront). The Blade storefront and the Next.js site each show only their own and the shared ones; menu items and footer links to a page that belongs to the other site are left out. The Slides and Pages lists show where each one appears, and picking a site on a slide follows that site's theme and homepage template. Offers were already separate: landing-page offers belong to the Blade storefront and Collections (Flash Sale, Trending, New Arrivals) to the Next.js site.

### Changed

- **WooCommerce, CRM messaging and lead follow-up reminders now use the Next.js site's settings (owner decision).** Once a company has a Next.js site it is the main storefront, so these company-level features read its settings (the WooCommerce tab on Integrations, the SMS/WhatsApp details and the *Lead Follow-up Reminders* section live on that site); a company without one keeps using its Blade storefront. The reseller store/program and the Blade abandoned-cart reminders stay on the Blade storefront settings.

### Technical Notes

- Migration `2026_10_07_120000_add_storefront_setting_id_to_slides_and_pages`: nullable `storefront_setting_id` (NULL = all sites) on `storefront_slides` and `storefront_pages`, `nullOnDelete` (deleting a site returns its slides/pages to all sites). `StorefrontSlide::forCompanyTheme()` takes the site (the cached company list is filtered in memory, so the cache key is unchanged); `StorefrontPage::forSite()` is used by the Blade page route, layout menus/footer, the API pages, and the API menu/footer resolution.
- `StorefrontSetting::primaryFor()` (Next.js row else Blade) and `isPrimary()` are read by `WooCommerceImportService`, `WooCommerceOrderSyncService`, `WooCommerceWebhookController`, the Integrations page, `Crm\BroadcastService`, `Crm\ConversationMessengerService` and `SendLeadFollowUpReminders`.
- Tests: new `StorefrontSiteContentTest` (8); new cases in `StorefrontApiTest`, `WooCommerceOrderWebhookTest`, `LeadFollowUpReminderTest`; `IntegrationsPageTest` updated for the primary-row WooCommerce tab. 3 new lang keys in `lang/en.json` and `lang/bn.json`.
## [2.58.0] - 2026-10-07

**Release type:** Minor Feature Update

### Changed

- **All reseller settings in one place: Resellers → Reseller Settings (owner rule).** A new page under the Resellers menu controls the whole reseller program for the current company: the module and its domains (**Reseller module enabled**, **Reseller subdomains enabled**, **Reseller custom domains enabled**, **Reseller portal domain** — Super Admin only, as before), the money rules (**Reseller cost per unit**, **Packaging charge per piece (own courier)**, **Minimum reseller withdrawal**), **Show reseller program** on the storefront, and the list of payment channels resellers pay own-courier advances into (with a link to Payment Methods, which the storefront checkout shares). These settings were removed from the company form and from Storefront Settings (its Reseller Settings section and the Footer Builder toggle). Opening the page needs the Settings: Manage permission.

### Technical Notes

- New `App\Filament\Pages\ResellerSettings` (Resellers cluster) writes `companies.reseller_*` (only for a Super Admin; the section is disabled for others and ignored on save) and `storefront_settings.reseller_*`; the portal domain is normalised and must be unique. `CompanyResource` and `StorefrontSettingResource` no longer have these fields; `HasStorefrontSettingsNavigation` drops the `reseller` section. No migration. New `ResellerSettingsPageTest` (5 tests); `ResellerCommissionTest` and `PhaseFourAdminPagesTest` updated. `docs/deployment.md` and `13_RESELLER_PORTAL_PLAN.md` point to the new page.
## [2.57.1] - 2026-10-07

**Release type:** Security Update

### Security

- **Signing up now needs an SMS code.** A new storefront account takes over the customer record (and order history) of the same phone number, so before, anyone who knew a customer's number could sign up with it and see that customer's past orders and address. Now, when the store has an SMS gateway, the sign-up form (Blade storefront and the Next.js site's API) texts a 6-digit code to the number and the account is created only after the code is entered. The code is valid for 10 minutes; five wrong codes throw it away; asking again within a minute does not send a second SMS. A taken phone or email is refused before any code is sent. Without an SMS gateway, a brand-new number still signs up in one step, but a number that already has a customer record cannot sign up (the visitor is asked to contact the store). In English and Bangla.

### Technical Notes

- `CustomerAccountService`: new `ensureCanRegister()`, `registrationVerification()` (`VERIFY_SMS` / `VERIFY_NONE` / `VERIFY_UNAVAILABLE`), `sendRegistrationCode()` / `verifyRegistrationCode()` (hashed code in the cache, keyed per company and normalized phone); `register()` accepts `password_hash`. Blade: `AccountAuthController::register()` keeps only the password hash in the session, new `account/register/verify` (GET/POST) and `account/register/resend` routes (domain + preview), new view `storefront/account/register-verify`. API: `POST /account/register` takes `code`; answers `202 verification_required`, `422 otp_invalid`, `422 verification_unavailable` (`docs/api/storefront-api.md`). Offer checkout's automatic account (random password) is unchanged. 11 new lang keys. `StorefrontApiAccountTest` +4, `StorefrontCustomerAuthTest` +2 (existing sign-up tests now go through the code). No migrations.
- **The Next.js website must handle `202 verification_required` (show a code field and resend the form with `code`)**; until it does, sign-up on the Next.js site fails on a store with an SMS gateway.
## [2.57.0] - 2026-10-07

**Release type:** Minor Feature Update

### Added

- **The Blade storefront and the Next.js site now have separate storefront settings (owner decision).** In Site → Storefront Settings a company has one row per site: the **Blade storefront** (the reseller store) and the **Next.js site** (the main storefront). Each is customised on its own — colours, fonts, content, menus, footer, contact details, checkout and delivery rules, payment gateway, notifications and Meta pixel. The list shows one row per site with a **Site** badge and that site's own domain. The Next.js row keeps the *Enable Next.js site API* switch, the Next.js domain and its verified flag; the Blade domain stays where it was.
- **New site → Site type.** Creating a storefront setting asks which kind of site it is; a company can have at most one of each. A new Next.js site can **start as a copy of the Blade storefront settings** (colours, content, checkout rules, integrations; credentials are carried over intact) or from blank defaults.
- **Integrations → Site.** When a company has both sites, the Integrations page asks which site's payment gateway, Meta pixel and website webhook settings you are editing. WooCommerce and the AI tools remain company-wide (WooCommerce uses the Blade row).

### Changed

- **Existing Next.js setup is split automatically.** Companies that had already switched on the Next.js site or given it a domain get their own Next.js row (a copy of their Blade settings, carrying the switch and domain); the Blade row loses those fields. Nothing changes for the Blade storefront, the reseller program or any existing order.
- The Next.js site API (`/api/v1/storefront/*`) now reads **only the company's Next.js row**; a company with just a Blade row answers `403 nextjs_site_disabled`.
- Website webhooks are sent for every site that has one configured (once per distinct URL).

### Technical Notes

- Migration `2026_10_07_100000_add_site_type_to_storefront_settings_table`: `storefront_settings.site_type` (`blade` default | `nextjs`), `unique(company_id, site_type)` replacing `unique(company_id)`, nullable `storefront_setting_id` on `orders` and `storefront_payments` (NULL = Blade storefront, every existing record), and the data split above (idempotent). Reversible.
- `Company::storefrontSetting()` is now the **Blade** row (all earlier code keeps working); new `Company::nextjsSetting()` / `storefrontSettings()`, `StorefrontSetting::bladeFor()` / `nextjsFor()` / `siteDomain()` / `recordedSiteId()`, `Order::siteSetting()` and `StorefrontPayment::siteSetting()` (own Next.js row, else Blade). `StorefrontCheckoutService::createOrder()` takes the site; ZiniPay/PayStation webhooks, Meta conversions/dispatch and the website webhook dispatcher read the order's or payment's own site. Abandoned-cart reminders, CRM messaging, lead reminders, WooCommerce and the slide pages stay on the Blade row.
- Tests: `NextjsSiteSettingsTest` rewritten (17 tests: separate rows, one per type, domain handling never touching the company domain, per-site sections, list, create with/without copy, migration split); new cases in `StorefrontApiTest`, `StorefrontApiCheckoutTest`, `StorefrontMetaTrackingTest`, `StorefrontPaystationPaymentTest`, `WebsiteWebhookDispatchTest`, `IntegrationsPageTest`. 9 new lang keys in `lang/en.json` and `lang/bn.json`.
## [2.56.0] - 2026-10-06

**Release type:** Minor Feature Update

### Added

- **Packaging charge on reseller own-courier orders.** New setting **Packaging charge per piece (own courier)** under Site → Storefront Settings → Reseller Settings. When a reseller sends an order with their own courier, this amount times the number of pieces is added to the order as a **Packaging charge** (shown on Create order's summary, in the reseller's order details, and on the admin order page and edit form). It is part of the total the reseller pays in advance and is never shown to the reseller's customer. Orders the store delivers never carry it. With the setting at 0 nothing changes.
- **A reseller's customer only ever sees the reseller's price and store (owner rules).** On an own-courier order the reseller sets the price their customer pays for each product (**Your customer's price**, at least the store price); the store still charges the reseller its own price. Everything the customer can see shows only the reseller's side: the printed invoice and the PDF invoice carry the reseller's **store name and logo** (not the company's name, logo, hotline, e-mail, address, website or social links), items and totals at the reseller's price, and a **Due Amount** equal to that total (also on the courier slip); no store price, packaging charge, advance or internal note; the courier and parcel ID are the reseller's. The shareable order summary card shows the reseller's store name with Total and Due. The reseller's portal shows their own sale as the **Sale amount** and, separately, **You pay the store**.
- **Resellers can change their own-courier orders (owner rules).** A **Change** button on the portal's Orders list opens the order until the store verifies any of the advance (and never once the parcel is packed or the order is closed). The reseller can add products, raise quantities, change their customer's prices, and correct the courier name, parcel ID and their customer's name, phone and address. Products already on the order cannot be removed or lowered. When the total goes up, the page shows the extra and asks for its payment details; the reseller sends **only the extra**, which is listed beside the first payment. Staff verify each payment in turn from the order page, and **Mark parcel packed** appears only once the whole total is verified. A rejected payment is sent again for the unpaid part only. The admin order page lists every payment and what is **Still to receive**; the portal's order details list them with what is **Still to send**.

- **Reseller deposit (owner rule).** When paying for an own-courier order the reseller can enter the **Amount sent** (blank means exactly what is owed; never less). When staff verify a payment they confirm the **Amount received** (they can correct it). Whatever the verified payments hold above the order's total goes to the reseller's **Deposit**, shown in the portal's top bar on every page. The deposit pays the advance of the reseller's next own-courier orders (and the extra after a change) automatically, before anything else; only the rest has to be sent, and when the deposit covers it all no payment details are asked. The deposit cannot be withdrawn. If less arrived than was owed, the order shows **Part received — send the rest** and the reseller sends the remainder.

### Changed

- **Staff can no longer add or change items on a reseller's own-courier order.** The Items list on the admin edit form is locked on these orders, with a note that only the reseller can change them from the portal (they have paid for these items in advance). Other order fields stay editable.

### Fixed

- **Confirming an order with too little stock did nothing.** Change status → Confirmed (on the order page or in bulk) on a draft whose products are short left the order as a draft with no visible message. It now names each short product with the ordered and in-stock quantities, and bulk Change status lists every skipped order with its reason.
- **Mark parcel packed on a reseller's draft order took no stock.** Packing now confirms the draft first, so the packed products leave stock; when stock is short it says which products and marks nothing.

### Technical Notes

- Migration `2026_10_06_100000_add_reseller_packaging_cost`: `storefront_settings.reseller_packaging_cost_per_unit`, `orders.packaging_fee` (both default 0) and `order_items.reseller_retail_price` (nullable). `OrderWorkflowService::sync()` and the admin order form's live total now add `packaging_fee`. New `Order::showsResellerPricesToBuyer()` / `buyerTotal()` and `OrderItem::buyerUnitPrice()` / `buyerSubtotal()` are used by every buyer-facing view (invoice partial, `orders.pdf`, `OrderSummaryCardService`, storefront account and tracking pages). Run migrations on deploy.
- Reseller order edits: `ResellerOrderPlacementService::quoteEdit()` / `updateOwnCourierOrder()`; new portal page `App\Filament\Reseller\Pages\EditOrder` (`/portal/orders/{record}/edit`). New `Order::resellerAdvancePayments()`; `ResellerOwnCourierService` gains `sentAmount()`, `outstandingAmount()`, `canEdit()`, and `submitPayment()` now records only the outstanding amount; `paymentState()` / `canMarkPacked()` look at all advance payments. `OrderPdfController` passes the reseller's logo. No new migration for this part.
- Migration `2026_10_06_110000_create_reseller_deposit_entries_table` (new company-owned `ResellerDepositEntry`: +overpayment / −used, balance = sum). `ResellerOwnCourierService::depositBalance()`, `applyDeposit()` (a completed `StorefrontPayment` with gateway `reseller_deposit` plus an `OrderPayment` with no account — the cash was already posted with the earlier order's payment, which records the full amount received), `verifyPayment(..., $received)` credits the overpayment, new payment state `short`. Deposit payments do not lock an order for the reseller's changes. `OrderPayment` sets `received_by` only for a staff `User` (a reseller signed in to the portal is a `Customer`). Top-bar badge via `PanelsRenderHook::USER_MENU_BEFORE` on the reseller panel. Run migrations on deploy.
- `OrderStatusWorkflowService` checks stock (`OrderStockCheckService::shortages()`) before moving an order into an accounted status and reports it under `stage`; `ResellerOwnCourierService::markPacked()` confirms a draft inside the same transaction.
- Tests: `ResellerOwnCourierOrderTest` (now 30 tests); `MultiCompanyIsolationTest` lists `ResellerDepositEntry`. New lang keys in `lang/en.json` and `lang/bn.json`.
## [2.55.0] - 2026-10-06

**Release type:** Minor Feature Update

### Added

- **Fraud check card on the Create order form.** Picking a customer still runs the courier fraud check, but the result is now a card in the same style as the **Fraud Checker** page: Safe / Risky / No courier history with the delivery rate, total / delivered / cancelled parcels, one line per connected courier, fraud reports from other merchants, a blacklist warning (with its reason) and this store's earlier orders for the number. **Full report** opens the Fraud Checker page for that number in a new tab, already checked. The **Courier Fraud Check** button (skips the 24-hour cache) stays. Now in English and Bangla.
- **Fraud Checker for resellers.** The reseller portal has a new **Fraud Checker** menu item with the same page, and its **Create order** page shows the same card as soon as the customer's phone number is typed. The check runs on the store's own courier accounts. Resellers see only the courier history and the verdict: never the store's customer names, order history or blacklist reasons, no "set up this courier" cards, and no **Refresh live data** button, so a reseller always gets the 24-hour cached result and cannot make the store's courier panels log in again and again.

### Technical Notes

- New `App\Support\FraudCheckReport` (summary, verdict, courier cards, store history, blacklist) and `App\Filament\Concerns\ChecksCourierFraud` (shared page logic; `showsStoreData()` / `canRefresh()` decide the differences), used by the admin `FraudChecker`, the new `App\Filament\Reseller\Pages\FraudChecker` and both create-order forms. Views moved to `resources/views/filament/fraud-check/` (`page`, new `compact`); the reseller theme now scans that folder. The page accepts `?phone=` and checks it on open. `OrderForm`'s inline-styled result table replaced by the compact card. 6 new lang keys. New `ResellerFraudCheckerTest` (6 tests); `FraudCheckerPageTest` +2 (10 total). No migrations.
## [2.54.1] - 2026-10-06

**Release type:** Security Update

### Security

- **Order details could be opened by any staff login.** The storefront preview's order-confirmation pages (`/storefront/<store>/checkout/success/<id>` and the offer thank-you page) had no check, so any signed-in staff member, whatever their role, could show a customer's name, phone and address by trying order numbers 1, 2, 3… These pages now need the same permission as opening the order in the admin panel ("Sales: View").
- **Staff could preview another company's storefront.** Eight storefront preview pages (pages, contact, complaints, cart, checkout, order tracking, reviews, account orders) did not check the company. A staff member of one company could open another company's preview by its address and even send a complaint into it. They now return "not found" unless the staff member belongs to that company (Super Admin still sees all).
- **Changing or resetting a customer password now signs out their other devices.** Before, an old login (for example on a shared or lost phone) stayed signed in, and "remember me" kept working, after the password was changed. The device that made the change stays signed in.
- **Only a Super Admin can change permissions now.** Before, a custom role with the "Users: Manage" permission could change any user's role (its own included, even to Super Admin), create roles, and change what each role may do. It could also edit, delete or reset the password of a Super Admin account. Now only a Super Admin can change a user's role, create a role or open the role permissions screen ("Manage Roles"), and only a Super Admin can change a Super Admin's account. Other staff with "Users: Manage" can still add users and edit names, emails, passwords, active status and companies. They see the role but cannot change it, and a user they add starts as Sales Staff until a Super Admin sets the role.
- **Limits on repeated tries:** the storefront preview's sign-in, register, forgot/reset-password and change-password forms, the live storefront's change-password form, and the Meta (Facebook/Instagram) message webhook now limit how many requests one address can send per minute, like the other sign-in forms already did.
- **Extra browser protection headers:** every page now tells the browser to use HTTPS only (sent on HTTPS pages only), and a basic Content-Security-Policy blocks other sites from framing the app, `<base>` tag hijacking and browser plugins. Scripts are not restricted yet, so nothing on the site changes.
- **Security updates for third-party packages:** Laravel 13.23 → 13.34 (debug-page XSS), Filament 5.7.5 → 5.8.4 (an already-used two-factor code could be used again), league/commonmark 2.8.3 → 2.10.3 (several denial-of-service and filter-bypass issues), league/flysystem 3.35 → 3.36. In the browser bundle, `@grpc/grpc-js` (pulled in by Firebase) is forced to 1.14.5. `composer audit` and `npm audit` now report no known vulnerabilities.

### Fixed

- **Storefront preview: the order-confirmation page crashed.** Links built for preview pages used the wrong parameter name, so the preview "Order placed" page showed an error instead of the order.

### Technical Notes

- New `App\Http\Middleware\InvalidateStaleCustomerSession` (appended to the `web` group): stamps `password_hash_customer` (same key and format as the reseller panel's `AuthenticateSession`) after each request and signs the customer guard out when it no longer matches. Only the customer guard is touched, so a staff session in the same browser is kept. `CustomerAccountService::updatePassword()` / `resetPassword()` also rotate `remember_token`.
- Each storefront controller's `previewStorefront()` now applies the same `canAccessCompany()` check as `PreviewController::previewCompany()`.
- `StorefrontUrl::prepend()` used the key `slug` for the preview route's `{company}` parameter (it also clashed with a route's own `{slug}`); it now uses `company`.
- Dependencies: `composer update` of only the affected packages (no `--with-dependencies`, so no major bumps of Guzzle etc.); `package.json` gains an `overrides` entry for `@grpc/grpc-js`. Filament's published assets in `public/` were refreshed by `filament:upgrade`.
- `SecurityHeaders`: `Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'` (not set when the response already has its own policy) and `Strict-Transport-Security: max-age=31536000` on secure requests (no `includeSubDomains`, since not every company/reseller domain is guaranteed HTTPS). A script-level CSP is still a separate pass (plan 09 step 3.4).
- `User::canChangePermissions()` (Super Admin only) and `canModifyUser()`. `Gate::before` denies every ability except `view`/`viewAny` on a Super Admin `User` record for non-Super-Admins. In `UserForm` the role field is disabled and the inline "Create role" refuses (403) for non-Super-Admins. `CreateUser` forces `User::defaultRole()` and `EditUser` drops `role` from the save, so a hand-crafted request can't change it. `UserRoleResource::canAccess()` requires a Super Admin (it used to be `canManageUsers()`). The users table's `DeleteBulkAction` now uses `authorizeIndividualRecords('delete')`; it previously deleted the whole selection with no per-record check. New `tests/Feature/UserManagementEscalationTest.php` (8 tests).
- Throttles: preview `account/login`, `register`, `forgot-password` (5/min), `reset-password`, `password` and the domain `account/password` at 10/min; `POST /webhooks/meta` at 600/min.
- New `tests/Feature/StorefrontPreviewAndSessionSecurityTest.php` (5 tests). `SecurityHardeningTest` gains 4 tests (CSP, own CSP kept, HSTS only on HTTPS, throttled routes).
## [2.54.0] - 2026-10-06

**Release type:** Minor Feature Update

### Fixed

- **"Already booked" for an order that was never booked.** Book courier treated an order as already booked whenever its **Delivery status** was anything but "Not booked". That field can move without any courier booking — chosen on the Create order form, or set to "In transit" by **Change status → Shipping** — so such an order was skipped by bulk Book courier ("0 order(s) booked · 1 already booked"), and its own Book courier button was hidden. Booking is now decided by the order's actual courier booking: an order with no live booking can be booked (including after a cancelled or failed booking). Orders that really cannot be booked are still skipped: a live booking exists, the order is delivered / partly delivered / returned, the order is cancelled / returned / refunded, or the reseller ships it with their own courier.
- **Bulk Book courier now says why each order was skipped**, one line per order with its number (for example "Already booked (tracking ID …)" or "The order is Cancelled."), instead of a single "already booked" count. The popup text is updated to match. In English and Bangla.
- **Order list: "Delivered" / "Returned" buttons read like a status.** Right after an order is booked, its row showed a green **Delivered** button (and a red **Returned** one). These are quick actions that mark the parcel delivered or returned by hand, not the order's status, but they looked like the order had been delivered. They are now **Mark delivered** / **Mark returned** (also on the order page), and the confirmation says it moves the order to Delivered without asking the courier. Booking itself never marks an order delivered: it stays at its order status with Delivery = Booking pending until the courier reports progress.
- **Courier callbacks that were turned away left no trace.** A callback with a missing or wrong Auth token (or when no token is saved on the provider) was rejected silently, so a Callback URL / token mismatch looked exactly like the courier never calling. Each rejected call is now listed in **Courier → Webhook Logs** as **Rejected**, with the reason and what to fix.
- **Courier actions that failed silently** — Sync courier status, Update status, Cancel booking, Mark delivered / returned — now show a success or failure message with the reason. A courier's HTTP error during a status sync becomes a readable reason (also in the scheduled sync's last error).
- **Steadfast callbacks are answered the way Steadfast's webhook guide shows** (200 with `{"status":"success","message":"Webhook received successfully."}` instead of 202).

### Added

- **Webhook Logs: Process now, and a warning when callbacks are stuck.** A saved callback is applied by the queue worker. When callbacks wait more than 5 minutes, the Webhook Logs page now says the queue worker does not seem to be running, and each waiting or failed callback has a **Process now** button.
- **Cancel booking explains itself:** the confirmation says it only marks the booking cancelled in this app and that the parcel must also be cancelled in the courier's own panel.

### Technical Notes

- New `Order::courierBookingBlocker()` (reads `latestCourierBooking`, which the orders list eager-loads). `OrdersTable::canBookCourier()` / new `courierBookingBlocker()` (adds the current-company check) and `ViewOrder::canBookCourier()` use it; `bookCourierBulk()` lists per-order skip reasons. 10 new lang keys. `OrderCourierBookingFeedbackTest` +4 tests (10 total). No migrations.
- `CourierWebhookController` logs rejected calls (`status = rejected`, deduped per body, payload kept only up to 16 KB) and answers Steadfast with 200. `CourierWebhookLogResource::processNowAction()` runs `ProcessCourierWebhook` inline; `ListCourierWebhookLogs::getSubheading()` warns on pending logs older than 5 minutes. `CourierBookingFeedback::run()` wraps the other courier actions. Status-sync client calls also go through `CourierService::callCourier()`. New `CourierStatusFlowAuditTest` (6 tests); `SteadfastApiGuideTest` / `CourierIntegrationTest` updated for the 200 response and the rejected-call log. 14 more lang keys.
## [2.53.0] - 2026-10-05

**Release type:** Minor Feature Update

### Added

- **Next.js site in Site → Storefront Settings.** A new **Next.js Site** section lets you switch the headless Next.js website (for example zamzamint.com) on or off, enter its domain and mark the domain verified. The settings list has a new **Sites** column that shows each company's Blade storefront and its Next.js site side by side. Both sites keep reading the same storefront settings (theme colours, fonts, contact details, menus, footer, banners, payment and delivery rules), so nothing is configured twice. A domain can't be reused by another company, a reseller's store or another Next.js site.
- **Collections in Offers.** **Offers** now has a third type, **Collection**, for **Flash Sale**, **Trending** and **New Arrivals**. Pick the products in display order, set when the collection starts and ends (a Flash Sale needs an end time, which runs its countdown) and optionally a **deal price** per product. Collections are only product lists for the Next.js site: they never appear as offer landing pages, in the `/offers` list or in the offer checkout, and aren't offered when generating AI images or videos.
- **Storefront API for the Next.js website** (`/api/v1/storefront/…`). The website, which has no backend of its own, reads site settings, categories, products (with images, variations, ratings, minimum order quantity and wholesale quantity prices), hero slides, live collections and content pages from the ERP. It uses the company's existing API key and works only while *Enable Next.js site API* is on. See `docs/api/storefront-api.md`.
- **Checkout for the Next.js website.** The website can now take real orders through the ERP (`POST /api/v1/storefront/checkout/quote`, `…/checkout`, `…/checkout/payments/{payment}/finalize`, `GET …/orders/{order_number}`). The website sends only the product, quantity and customer details; the ERP works out every price, delivery charge (inside/outside Dhaka from the address), VAT, new-customer delivery advance, pre-order advance, courier-risk advance, checkout-policy block and the payment options exactly as the Blade checkout does, and starts the ZiniPay/PayStation payment when an advance or full online payment is needed. The order is created only after the gateway confirms the payment. A double click or a retry never places a second order, a changed total is refused with the new total, and a stale cart (minimum order quantity, stock) is reported instead of being changed silently.
- **Wholesale price rule for the website (owner decision):** a live Flash Sale / Trending / New Arrivals **deal price** wins over wholesale quantity prices; otherwise the quantity price reached applies; otherwise the normal selling price. A variation always keeps its own price, and a deal that is not lower than the selling price is ignored.
- **Customer accounts, order tracking and reviews for the Next.js website.** Customers of the website can create an account, sign in with a password or with a 6-digit code sent by SMS or email, reset a forgotten password by SMS, see their order history, follow an order's status and courier updates, order the same items again, change their profile and password, and review the products of an order once it is completed. It works like the Blade storefront's account pages and uses the same **Customer accounts** switch in Storefront Settings, the same SMS gateway and the same rules (a registration reuses the customer record of the same phone number, so earlier orders appear straight away). Anyone can also track an order without an account with the order number and the phone number it was placed with, or with the link on the order confirmation. A new review waits in **Product Reviews** until you approve it, exactly as before.

### Fixed

- **Signing in with an e-mail address ignored capital letters inconsistently.** The password sign-in compared the e-mail exactly while the one-time-code sign-in did not, so `Name@Example.com` worked for one and not the other. Both now ignore capitals.

### Security

- **A password-reset code can no longer be guessed.** Five wrong reset codes now lock that account's reset until the code's 15-minute window ends (before, the 6 digits could be tried without limit). This protects both the Blade storefront and the new website.

### Technical Notes

- `CLAUDE.md` (agent memory) now records that zamzamint.com is the wholesale ZamZam International site and zamzamgadgetbd.com the retail ZamZam Gadget site, and the owner's decisions for the Next.js website. `Company::CORE_COMPANIES` and `docs/storefront-go-live.md` still carry the old "Gift Items / Gadget Items" labels (not changed here).
- New migrations (run `php artisan migrate --force`; both only add columns, existing rows are unchanged): `2026_10_03_100000_add_nextjs_site_to_storefront_settings_table` (`nextjs_site_enabled` default off, unique `nextjs_site_domain`, `nextjs_site_domain_verified`) and `2026_10_03_110000_add_collection_fields_to_offers_tables` (`offers.collection_kind`, `starts_at`, `ends_at`; `offer_items.deal_price`).
- `Offer::TYPE_COLLECTION`; scopes `landingPages()`, `collections()`, `liveCollections()`. `OfferController`, `OfferCheckoutController` and the image/video generation offer pickers now use `landingPages()`. Switching an offer away from *Collection* clears its kind and schedule.
- `StorefrontSetting::nextjsDomainConflict()` / `nextjsDomainTaken()`; `Company::hostIsTakenByThePlatform()` also treats a Next.js domain as taken. Server-side checks live in `HandlesNextjsSiteDomain` (Create/Edit pages) in addition to the form rules.
- New `EnsureNextjsSiteEnabled` middleware (403 `nextjs_site_disabled`), the `storefront-api` rate limiter (600/min per API token + 3000/min per IP; defined in `AppServiceProvider`), `StorefrontController`, `StorefrontCatalogService` and `StorefrontSiteConfigService`. `Product::reviews()` relation added. No new company-owned model, so `MultiCompanyIsolationTest` is unchanged; `StorefrontApiTest` covers isolation between two companies.
- The existing server-to-server `/api/v1` routes are untouched and are not gated by the Next.js switch.
- **Phase 2 (checkout) —** the Blade `Storefront\CheckoutController` now delegates to the new shared `StorefrontCheckoutService::process()` (totals, checkout policy, advance eligibility, online/full payment, order creation) with no behaviour change; the API controller `Api\V1\StorefrontCheckoutController` calls the same method. New: `CheckoutOutcome`, `StorefrontApiCartResolver` (Blade line rules without silent changes), `ForceJsonResponse` middleware (storefront API only), `Support\StorefrontApi\{Signing,OrderSummary}`, `StorefrontCatalogService::unitPrice()` / `dealFor()`, `StorefrontMetaTrackingService::contextFromClient()`, and an optional `$clientIp` argument on `StorefrontCheckoutPolicyService::evaluate()`.
- No migration and no new model. Payment `ref` and order `token` are HMACs of the payment/order id and company derived from `APP_KEY` (rotating `APP_KEY` invalidates links already sent to customers). `return_url`/`cancel_url` must be https on the company's `nextjs_site_domain` (localhost allowed only in `local`/`testing`). Visitor IP comes from the website (`client_ip`); without it IP rules are skipped.
- Tests: new `StorefrontApiCheckoutTest` (26) and a deal-rule case in `StorefrontApiTest`; the existing Blade checkout, payment and policy tests pass unchanged.
- 32 new translation keys in `lang/en.json` and `lang/bn.json`.
- **Phase 3 (accounts, tracking, reviews).** Migration `2026_10_06_100000_create_storefront_customer_tokens_table` (run `php artisan migrate --force`; a new table, nothing existing changes): `storefront_customer_tokens` (company, customer, SHA-256 token hash, expiry, last use) with the company-scoped model `StorefrontCustomerToken`, added to `MultiCompanyIsolationTest`. The website's server sends the token as `X-Customer-Token` next to the company API key (`AuthenticateStorefrontCustomer`, `EnsureStorefrontAccountsEnabled` middleware, `StorefrontCustomerSession`).
- New `Api\V1\StorefrontAccountAuthController`, `StorefrontAccountController` and `StorefrontOrderTrackingController`; `Support\StorefrontApi\{AccountPayload,TrackingSummary,Phone}`; `OrderSummary` gains `status_label`, `order_date` and the product `slug` per item. The `storefront-auth` rate limiter (20/min per visitor — the `client_ip` the website forwards, not its own address).
- New shared `StorefrontOrderTrackingService` (status history + courier) now also feeds the Blade tracking page, whose output is unchanged. `CustomerAccountService::resetPassword()` gained the attempt lock and `resetLockedFor()`; `attemptLogin()` matches e-mail case-insensitively.
- Phones from the website are normalised to the local `01XXXXXXXXX` form (the same as checkout) before any lookup, so `+880…` and `01…` are one customer and one lockout counter.
- Tests: new `StorefrontApiAccountTest` (33) — gates, registration, sign-in, lockouts, codes, reset, profile/password, orders, reorder, reviews, tracking, cross-company and per-visitor limits.
- No new translation keys in the ERP: the API answers with stable error codes and the website words them.
## [2.52.0] - 2026-10-05

**Release type:** Minor Feature Update

### Fixed

- **Book courier did nothing when the booking failed.** Clicking **Book courier** (order list or order page) or **Book Steadfast** showed nothing and booked nothing when the booking could not go through. When the courier refused it (wrong phone, wrong API keys, duplicate invoice, ...), the courier's error was not caught at all. A customer-risk block or a provider mismatch was hidden because it matched no field in the popup. Now a successful booking shows **Courier booked** with the tracking ID. A failed one shows **Courier booking failed** with the reason — the courier's own message, "could not reach the courier", or the risk review that blocks it — and the popup stays open so the courier or details can be changed. In English and Bangla.
- **Bulk Book courier stopped at a refused order.** A courier refusal on one order no longer stops the rest of the batch. The result now lists each failed order with its reason (it used to say "see Status Logs", where nothing was recorded).

### Added

- **Upsell filter on the orders list.** New **Upsell items** filter: **With upsell items** shows orders that had an item added after they were created, **Without upsell items** the rest. Admin-only, like the Upsell tag. In English and Bangla.

### Technical Notes

- `CourierService::callCourier()` wraps the Steadfast / Pathao / RedX / E-Courier create calls and turns `RequestException` / `ConnectionException` into a `ValidationException` (key `courier`) with the courier's message. New `Orders\Actions\CourierBookingFeedback` (notifications + `halt()`), used by the order list's **Book courier** and the order page's **Book courier** / **Book Steadfast**; `bookCourierBulk()` reports per-order reasons. `TernaryFilter has_upsell` on `OrdersTable` (`whereHas('items', is_upsell)`). New `OrderCourierBookingFeedbackTest` (6 tests). No migrations.
## [2.51.0] - 2026-10-05

**Release type:** Minor Feature Update

### Fixed

- **Items could not be added to an order after it was created.** On a confirmed order, adding an item failed with "Insufficient stock" — even after stock was added to the new item's product. The block came from one of the order's *existing* items: when that product's stock had gone below zero (for example after a WooCommerce sale, which is never blocked), every save re-checked the unchanged line and refused it. Lines whose quantity does not go up are no longer blocked, and adding stock to a product that is below zero is no longer refused either; only stock actually being taken out must be available.

### Added

- **"Not enough stock" popup when editing an order.** Raising a quantity or adding an item on a confirmed / processing / completed order now shows the same popup as Create order when something is short, asking only for the stock beyond what the order already took ("Adding · In stock · Short by"). **Add stock and save order** adds the stock and saves. If a product is already below zero, the popup asks for enough to cover that too. WooCommerce orders are never stopped. In English and Bangla.
- **Upsell tag.** An item added to an order after it was created is saved as an upsell and shows an **Upsell** badge under the product on the order's edit and view pages. The tag is admin-only — the invoice, PDF, storefront tracking and the customer's account do not show it.

### Technical Notes

- `StockMovementService::validate()` blocks negative stock only when the movement lowers stock compared with what it booked before (new `takesStock()`). `OrderStockCheckService::shortages()` takes the saved order and subtracts its booked sale quantities; `short` now covers stock already below zero. Popup moved from `CreateOrder` into the new `Pages/Concerns/HandlesStockShortages` trait, used by `CreateOrder::beforeCreate()` and `EditOrder::beforeSave()`. Migration `2026_10_05_110000_add_is_upsell_to_order_items_table` adds `order_items.is_upsell` (boolean, default false); the order form's items repeater sets it on rows created on the edit page. New `OrderUpsellTest` (6 tests); `OrderStockShortageTest` now 11 tests.
## [2.50.0] - 2026-10-05

**Release type:** Minor Feature Update

### Added

- **Reseller portal: Forgot password.** The portal sign-in page now has a **Forgot password?** link. The reseller enters their phone number and gets a 6-digit code by SMS (valid for 15 minutes, the same reset code as the storefront account), then sets a new password and is signed straight in. **Send a new code** goes back to the phone step. A code is sent only to a pending or approved reseller of that company, and the reply is the same for any number, so the form does not reveal who is a reseller. Needs the store's SMS settings (Storefront → Notifications); without them the page says reset by SMS is not available and to contact the store.

### Technical Notes

- New `App\Filament\Reseller\Pages\Auth\ForgotPassword` (extends Filament's `RequestPasswordReset`, both steps on one page) registered with `->passwordReset()` on the reseller panel; reuses `CustomerAccountService::sendPasswordResetCode()` / `resetPassword()`. Filament's e-mailed link is not used. Tests: `ResellerPortalPasswordResetTest` (includes a run through the real portal Livewire endpoint).
## [2.49.0] - 2026-10-05

**Release type:** Minor Feature Update

### Added

- **Next.js site in Site → Storefront Settings.** A new **Next.js Site** section lets you switch the headless Next.js website (for example zamzamint.com) on or off, enter its domain and mark the domain verified. The settings list has a new **Sites** column that shows each company's Blade storefront and its Next.js site side by side. Both sites keep reading the same storefront settings (theme colours, fonts, contact details, menus, footer, banners, payment and delivery rules), so nothing is configured twice. A domain can't be reused by another company, a reseller's store or another Next.js site.
- **Collections in Offers.** **Offers** now has a third type, **Collection**, for **Flash Sale**, **Trending** and **New Arrivals**. Pick the products in display order, set when the collection starts and ends (a Flash Sale needs an end time, which runs its countdown) and optionally a **deal price** per product. Collections are only product lists for the Next.js site: they never appear as offer landing pages, in the `/offers` list or in the offer checkout, and aren't offered when generating AI images or videos.
- **Storefront API for the Next.js website** (`/api/v1/storefront/…`). The website, which has no backend of its own, reads site settings, categories, products (with images, variations, ratings, minimum order quantity and wholesale quantity prices), hero slides, live collections and content pages from the ERP. It uses the company's existing API key and works only while *Enable Next.js site API* is on. See `docs/api/storefront-api.md`.
- **Checkout for the Next.js website.** The website can now take real orders through the ERP (`POST /api/v1/storefront/checkout/quote`, `…/checkout`, `…/checkout/payments/{payment}/finalize`, `GET …/orders/{order_number}`). The website sends only the product, quantity and customer details; the ERP works out every price, delivery charge (inside/outside Dhaka from the address), VAT, new-customer delivery advance, pre-order advance, courier-risk advance, checkout-policy block and the payment options exactly as the Blade checkout does, and starts the ZiniPay/PayStation payment when an advance or full online payment is needed. The order is created only after the gateway confirms the payment. A double click or a retry never places a second order, a changed total is refused with the new total, and a stale cart (minimum order quantity, stock) is reported instead of being changed silently.
- **Wholesale price rule for the website (owner decision):** a live Flash Sale / Trending / New Arrivals **deal price** wins over wholesale quantity prices; otherwise the quantity price reached applies; otherwise the normal selling price. A variation always keeps its own price, and a deal that is not lower than the selling price is ignored.

### Technical Notes
- `CLAUDE.md` (agent memory) now records that zamzamint.com is the wholesale ZamZam International site and zamzamgadgetbd.com the retail ZamZam Gadget site, and the owner's decisions for the Next.js website. `Company::CORE_COMPANIES` and `docs/storefront-go-live.md` still carry the old "Gift Items / Gadget Items" labels (not changed here).
- New migrations (run `php artisan migrate --force`; both only add columns, existing rows are unchanged): `2026_10_03_100000_add_nextjs_site_to_storefront_settings_table` (`nextjs_site_enabled` default off, unique `nextjs_site_domain`, `nextjs_site_domain_verified`) and `2026_10_03_110000_add_collection_fields_to_offers_tables` (`offers.collection_kind`, `starts_at`, `ends_at`; `offer_items.deal_price`).
- `Offer::TYPE_COLLECTION`; scopes `landingPages()`, `collections()`, `liveCollections()`. `OfferController`, `OfferCheckoutController` and the image/video generation offer pickers now use `landingPages()`. Switching an offer away from *Collection* clears its kind and schedule.
- `StorefrontSetting::nextjsDomainConflict()` / `nextjsDomainTaken()`; `Company::hostIsTakenByThePlatform()` also treats a Next.js domain as taken. Server-side checks live in `HandlesNextjsSiteDomain` (Create/Edit pages) in addition to the form rules.
- New `EnsureNextjsSiteEnabled` middleware (403 `nextjs_site_disabled`), the `storefront-api` rate limiter (600/min per API token + 3000/min per IP; defined in `AppServiceProvider`), `StorefrontController`, `StorefrontCatalogService` and `StorefrontSiteConfigService`. `Product::reviews()` relation added. No new company-owned model, so `MultiCompanyIsolationTest` is unchanged; `StorefrontApiTest` covers isolation between two companies.
- The existing server-to-server `/api/v1` routes are untouched and are not gated by the Next.js switch.
- **Phase 2 (checkout) —** the Blade `Storefront\CheckoutController` now delegates to the new shared `StorefrontCheckoutService::process()` (totals, checkout policy, advance eligibility, online/full payment, order creation) with no behaviour change; the API controller `Api\V1\StorefrontCheckoutController` calls the same method. New: `CheckoutOutcome`, `StorefrontApiCartResolver` (Blade line rules without silent changes), `ForceJsonResponse` middleware (storefront API only), `Support\StorefrontApi\{Signing,OrderSummary}`, `StorefrontCatalogService::unitPrice()` / `dealFor()`, `StorefrontMetaTrackingService::contextFromClient()`, and an optional `$clientIp` argument on `StorefrontCheckoutPolicyService::evaluate()`.
- No migration and no new model. Payment `ref` and order `token` are HMACs of the payment/order id and company derived from `APP_KEY` (rotating `APP_KEY` invalidates links already sent to customers). `return_url`/`cancel_url` must be https on the company's `nextjs_site_domain` (localhost allowed only in `local`/`testing`). Visitor IP comes from the website (`client_ip`); without it IP rules are skipped.
- Tests: new `StorefrontApiCheckoutTest` (26) and a deal-rule case in `StorefrontApiTest`; the existing Blade checkout, payment and policy tests pass unchanged.
- 32 new translation keys in `lang/en.json` and `lang/bn.json`.
## [2.48.0] - 2026-10-05

**Release type:** Minor Feature Update

### Added

- **Reseller orders with the reseller's own courier.** On **Create order** a reseller now chooses **The store delivers (cash on delivery)** or **My own courier (I pay the full amount in advance)**. With their own courier they enter the courier name and the parcel/consignment ID they booked, and the advance payment details: which of the store's payment methods they paid to (the active send-money/bank methods from Storefront → Payment Methods), the number or account they paid from, and the transaction ID. These orders are charged at the store's price (the base price; the normal price for a product with no wholesale rate), with no delivery charge and no commission — the reseller pays that total in advance. The same parcel ID cannot be used on two of the reseller's orders. If the store has no payment method set up, the option is shown but disabled.
- **Checking and packing these orders (admin).** The order page shows a **Reseller's own courier** section with the parcel ID in large type, the courier, the payment state and transaction details, and when it was packed; the store's courier booking is hidden for these orders. **Verify reseller payment** (choosing the account the money arrived in) records it in the order's Payments History so the order shows as paid; **Reject reseller payment** sends it back to the reseller. **Mark parcel packed** is available only after the payment is verified. The order list has a **Reseller's own courier** filter and a hideable **Reseller parcel ID** column (searchable). The advance also appears on Storefront → Payments as **Reseller advance (own courier)** with a **Check on order** link.
- **Reseller's view.** The portal **Orders** list shows the parcel ID with the payment state (waiting, verified, rejected) or **Packed**, and the order details show the courier, parcel ID, payment and packing. A rejected payment can be sent again with **Send payment again**.

### Changed

- **Reseller portal: Create order is a button.** **Create order** is no longer in the portal menu; it opens from a **Create order** button at the top of the **Orders** page.

### Technical Notes

- Migration `2026_10_05_100000_add_reseller_own_courier_to_orders_table`: `orders.reseller_own_courier`, `reseller_courier_name`, `reseller_parcel_id`, `reseller_parcel_packed_at` (+ index on reseller and parcel ID). Run migrations on deploy.
- The advance is a manual `StorefrontPayment` with the new purpose `reseller_advance` (`Order::resellerAdvancePayment()`); new `App\Services\ResellerOwnCourierService` (submit, verify, reject, mark packed). `ResellerOrderPlacementService::place()` takes an optional own-courier array. Own-courier orders get no default courier provider and skip the courier fraud check. Tests: `ResellerOwnCourierOrderTest`.
## [2.47.0] - 2026-10-05

**Release type:** Minor Feature Update

### Fixed

- **Orders with two or more items would not save.** When a confirmed (or processing / completed) order had an item that was out of stock, saving did nothing visible: the "Insufficient stock" error had no field to show on, and the order was left half-saved with only some of its stock taken out. The order is now saved all-or-nothing, and a stock error while saving or editing an order is shown as a message.

### Added

- **"Not enough stock" popup on Create order.** Placing a confirmed / processing / completed order now checks every item first. If any product (or variation) is short, a popup lists each one with how many were ordered, how many are in stock and how many are missing. Staff who can add stock can type how many to add (at least the missing amount) and press **Add stock and place order** — the stock is added as a stock adjustment ("Stock added from the order form") and the order is placed. Others see the list and go back to change the items. Draft orders do not take stock out, so they are saved without the popup. In English and Bangla.

### Technical Notes

- New `App\Services\OrderStockCheckService` (`shortages()` totals lines per product + variant like `OrderWorkflowService::syncStockMovements()`; `addStock()` books an `adjustment` movement). `CreateOrder::beforeCreate()` mounts the `stockShortage` action and halts. `CreateOrder` and `EditOrder` now use database transactions and the new `ReportsStockErrors` trait. New `OrderStockShortageTest` (7 tests). No migrations.
## [2.46.0] - 2026-10-04

**Release type:** Minor Feature Update

### Added

- **Fraud Checker page (Courier → Fraud Checker).** Type a customer's mobile number and see, before taking an order, how that number has done on your connected couriers (Pathao, Steadfast, RedX). The page shows a Safe / Risky verdict, the overall delivery success rate, total / delivered / cancelled parcel tiles, a card per courier with its own numbers and success bar (or "Not configured" with a link to Courier → Providers), fraud reports filed by other merchants (Steadfast), a blacklist warning when the number is on your blacklist, and this store's own order history for the number. **Refresh live data** skips the 24-hour cache. Safe / Risky uses your existing **External courier success ratio review threshold** from Customer Risk Settings — no new rules. Open to staff who can view sales. In English and Bangla.

### Technical Notes

- New `App\Filament\Pages\FraudChecker` + `filament.pages.fraud-checker` view. Reuses `ExternalCourierFraudService::checkByPhone()` (same cache and `customer_risk_events` audit log as the order form's Courier Fraud Check); the service gains `configuredDrivers()` and a public `normalizePhone()`. Blacklist lookup filters company explicitly (global + current company). New `FraudCheckerPageTest` (8 tests). No migrations.
## [2.45.0] - 2026-10-04

**Release type:** Minor Feature Update

### Added

- **Reseller portal: Create order.** An approved reseller can now place an order for their own customer from the portal (new **Create order** page, second in the menu). They enter the customer's name, phone, delivery address and optional note, then add products from their own store with a quantity and their own selling price (never below the base price; a product with no wholesale rate sells at the normal price and earns no commission). The page shows the live subtotal, the store's delivery charge for that address, VAT, what the customer pays (cash on delivery) and the reseller's commission. The order arrives as a draft for the store to review and confirm, like a storefront order, shows up in the reseller's **Orders** list with its status, and the commission is credited as usual. A customer is matched by phone; a phone that belongs to another reseller's account only receives the order (that reseller's own details are not overwritten). Orders from the portal are labelled **Reseller portal** in the admin.

### Fixed

- **New-order alert link.** The "New order received" alert (and the status-change alert) now always links to the admin order page. It used the current panel's order page, which does not exist when the order is created from the reseller portal.
## [2.44.0] - 2026-10-03

**Release type:** Minor Feature Update

### Added

- **Reseller store logos.** On the portal's **My Store** page a reseller can upload their own logo — one for light mode and an optional one for dark mode. It replaces the store name at the top of their store (the company's own logo is never shown there); with no logo the store name is shown as before. Uploads are compressed to WebP like other logos. The always-dark marketplace theme header shows the dark logo.

### Changed

- **Reseller portal wording and layout.** The link under the sign-in form now reads **Become a reseller** (it said "sign up for an account"). **My Store** has a **Visit store** button that opens the store, in place of **Copy link**. On the dashboard, **Recent orders** now sits above the commission chart.

## [2.43.0] - 2026-10-03

**Release type:** Minor Feature Update

### Added

- **Server check for Bangla ad images** (groundwork for the AI Tools → Content Creation ad builder). An admin can run `php artisan ad-creative:check-rendering --render` on the server to see whether it can put Bangla text on an image correctly. The image library already in the app (GD) breaks Bangla: conjuncts like স্বাস্থ্য don't join, and ি / ে land on the wrong side of the letter. The check reports OK or FAIL for each requirement and, with `--render`, saves a sample picture to look at.

### Technical Notes

- New `App\Services\AdCreative\TextRenderingCapability` and `ad-creative:check-rendering` command. Checks: GD, the `imagick` extension, the ImageMagick `raqm` delegate (HarfBuzz/FriBidi shaping), and a probe: ক্ষ drawn shaped must be at most 0.60 of its three code points' combined width (measured: Imagick + raqm 0.51, GD 0.81). Exits non-zero when not ready.
- **Deploy change:** `ext-imagick` added to `composer.json` `require`, the only way Nixpacks installs a PHP extension; `composer.lock` changes only its content-hash and platform list. CI (`.github/workflows/deploy.yml`) now installs `imagick`. Local machines without imagick: `composer install --ignore-platform-req=ext-imagick` (the app itself still boots).
- Bundled font: Hind Siliguri Regular/Bold (SIL OFL 1.1, Indian Type Foundry) in `resources/fonts/ad-creative/` with `OFL.txt`.
- Plan: `14_AI_TOOL_MENU_CONTENT_CREATION_PLAN.md` (Phase 0 results).

## [2.42.2] - 2026-10-03

**Release type:** Hotfix Update

### Fixed

- **Reseller portal: "Become a reseller" and sign-in did nothing.** Submitting either form on `reseller.<domain>` neither saved the application nor signed anyone in, and showed no error (so admin never saw the applicants). Two causes: the portal host sent the app's own form-submit address back to the portal page (the app uses a randomised `livewire-<code>/update` address, which the host rule did not recognise), and, once that passes, the company was not resolved from the portal address on those submits, so an application would have been saved under the default company. Both fixed; wrong passwords now show the error message again. Applications that were attempted while this was broken were never saved — applicants need to apply again.

### Technical Notes

- `RestrictResellerPortalHost` lets every `livewire`/`livewire-<hash>` path through; `ResellerPanelProvider` marks `ResolveCompanyFromResellerPortalDomain` as persistent middleware. New `ResellerPortalLivewireHttpTest` posts the real Livewire update request (apply, wrong password, login).

## [2.42.1] - 2026-10-03

**Release type:** Hotfix Update

### Fixed

- **Reseller portal showed "500 Something went wrong" to everyone not logged in, and failed storefront forms showed the same page.** In production the app swaps unexpected errors for a plain error page, but it also did that for two things that are not errors: "you need to log in" and "a form field is invalid". So opening `reseller.<domain>/portal` as a guest (instead of being sent to the sign-in page) and, outside the admin panel, a wrong password or an invalid storefront form (instead of coming back with the message) ended on the 500 page. Both now behave normally. Real errors still show the plain error page. Local and test runs never showed this, because the swap is off there.

### Technical Notes

- `bootstrap/app.php`: the production error-page render hook now skips `AuthenticationException`, `ValidationException` and `HttpResponseException`. New `ProductionErrorPagesTest` runs as the production environment.

## [2.42.0] - 2026-09-29

**Release type:** Minor Feature Update

### Added

- **Remove link button in the Documentation article editor.** Click inside a link and press the new "Remove link" (broken-chain) button in the toolbar to remove the link and keep the text — no need to retype it.

**Technical Notes:**
- Filament's `RichEditor` has no built-in unlink tool, so `DocumentationArticleResource` registers a custom `unlink` `RichEditorTool` (`extendMarkRange('link').unsetLink()`) and lists it in `toolbarButtons()` next to `link`.

## [2.41.0] - 2026-09-29

**Release type:** Minor Feature Update

### Added

- **Channel partners are now separate from investors.** A new **Investments → Channel Partners** section keeps each partner's own record: name, phone, address, **NID / passport as the partner ID** (it can't repeat, and Bangla digits and spaces don't matter), a payment account (bKash / Nagad / Rocket / bank / cash, with account number, account name, bank and branch), notes, and an active switch.
  - **Accounts on the partner page:** how many investors they brought in, those investors' active capital, and the commission earned, paid and due. There are tabs for the investors they brought in, their commission (one row per settlement, with a link to the voucher), and their documents.
  - **Documents:** new types for the channel partner's **NID / passport copy** and **agreement**. Like investor NID copies, only users with `investments.manage` can see or download them.
  - A partner who has investors or payouts can't be deleted.

### Changed

- **Channel partners no longer come from the investor list.** On the investor form, in the import dialogs, in the draft review and in **Assign channel partner**, the channel partner is picked from the Channel Partners list. The investor form's "This investor is also a Channel Partner" switch, the "Partner" column, the "Channel Partners" filter and the partner payout tab on investors are gone. Each investor still shows the partner who brought them, and the **Channel Partner** filter on the investor list still works.
- **A project can have several channel partners.** The settlement pays each partner separately: the project's channel-partner share, in proportion to the capital of the investors that partner brought in. Investors without a partner still add to the company's share, as before. The old rule "a project currently supports only one channel partner" is removed.
- **Import:** a row's channel-partner column is matched against Channel Partners by partner ID (NID), phone or exact name. The "is channel partner" column is no longer read.

**Technical Notes:**
- New migration `2026_09_28_150000_create_channel_partners_table`. Run `php artisan migrate --force`. It is not reversible. What it does:
  - creates `channel_partners`.
  - copies every investor who acted as a partner (flagged, named as someone's partner, or paid a partner payout) into it, keeping `legacy_investor_id`.
  - repoints `investors.channel_partner_id` and `investor_drafts.channel_partner_id` to `channel_partners`.
  - moves `channel_partner_payouts.investor_id` to `channel_partner_id`, with one payout per partner per settlement.
  - drops `is_channel_partner` from `investors` and `investor_drafts`.
  - the old investor rows are kept because they may have their own investments. A row that was only a partner still appears in the investor list and can be deleted there if it has no investments.
- New `ChannelPartner` model (`BelongsToCompany`, `User::MODEL_MODULES` → `investments`, audited, added to `MultiCompanyIsolationTest`) and `ChannelPartnerResource`, with `ReferredInvestorsRelationManager`, `PayoutsRelationManager` and the shared `DocumentsRelationManager`. `PartnerPayoutsRelationManager` on investors is deleted.
- `SettlementService::allocateProfit()` returns `partner_rows` (partner id => amount), and one `ChannelPartnerPayout` is created per partner. `Investment` no longer rejects a second partner in a project.

## [2.40.0] - 2026-09-29

**Release type:** Minor Feature Update

### Added

- **View button on the Documentation articles list.** Opens the article's public `/guides` page in a new tab. Only shown for articles that are both Published and Publicly shareable — otherwise the page wouldn't exist to view.

**Technical Notes:**
- `DocumentationArticleResource` table: `viewGuidePage` action, visible only when `is_published && is_public`; table query eager-loads `category` to avoid an N+1 for both this action and the existing Category column.

## [2.39.0] - 2026-09-29

**Release type:** Minor Feature Update

### Added

- **Documentation article slug auto-fills from the title.** Type a Title and the Slug fills in on its own — no need to type it manually. Editing the Slug yourself is still possible; once it has a value, typing in Title no longer overwrites it.
- **Public Guides page for sharing Documentation with people outside the admin panel.** `/guides` lists every published *and* publicly-shareable Documentation article by category, and `/guides/{category}/{article}` shows one — no login required. Meant for people who need to read the guides (e.g. an external developer team) but should not get an admin panel account: Dashboard and other admin pages show business data to any signed-in user regardless of permissions, so a limited account is not a safe way to share this.
- **Documentation article: "Publicly shareable" toggle.** Off by default, so nothing is exposed at `/guides` unless deliberately turned on. An article stays visible to signed-in staff in the Documentation menu either way — the toggle only controls whether it also shows up at the public, no-login `/guides` page.

**Technical Notes:**
- Title's `afterStateUpdated` sets `slug` to `Str::slug($title)` only while `slug` is still empty — same pattern as `OfferForm`.
- New `DocumentationGuideController` (`index`, `show`) + `resources/views/guides/*` + `resources/views/components/layouts/guides.blade.php`. Only articles that are both `is_published` and `is_public` are queryable there; anything else 404s (unpublished, or published-but-internal). The internal Documentation page and its `is_published`-only filter are unchanged. New migration `2026_09_29_090000_add_is_public_to_documentation_articles_table` (default `false`). `/docs` was already taken by the existing product marketing page, so this uses `/guides`.

## [2.38.0] - 2026-09-29

**Release type:** Minor Feature Update

### Added

- **Upload a file to import Markdown into a Documentation article.** The **Import Markdown** modal (**Settings → Documentation → article → Content**) now also accepts a `.md`/`.txt` file upload, alongside the existing paste box — either works.

**Technical Notes:**
- The upload uses `FileUpload::storeFiles(false)`, so the file is never written to disk: its raw `TemporaryUploadedFile` content is read directly and discarded once converted through the same `DocumentationArticleResource::markdownToHtml()` used by the paste box.

## [2.37.0] - 2026-09-28

**Release type:** Minor Feature Update

### Added

- **Import Markdown into Documentation articles.** The article editor (**Settings → Documentation**) has an **Import Markdown** button next to the Content field. Paste raw Markdown (headings, bold, lists, tables, code blocks) and it converts to properly formatted rich text — pasting Markdown directly into the editor only produced a plain paragraph.

**Technical Notes:**
- **Documentation Markdown import:** `DocumentationArticleResource::markdownToHtml()` uses `league/commonmark`'s `GithubFlavoredMarkdownConverter` (was already a transitive dependency; now declared directly in `composer.json`). Wired to the Content field's `hintAction`.

## [2.36.0] - 2026-09-28

**Release type:** Minor Feature Update

### Changed

- **Moving a payout voucher to trash makes the payout unpaid again.** This covers investor settlement payouts, channel-partner payouts and reseller withdrawal requests. If the voucher that was created when the payout was marked paid goes to **Trash**, the payout goes back to **pending**, or **approved** for reseller requests, and can be paid again. Restoring the voucher marks it paid again. A settlement that was fully paid out goes back to **confirmed** while any payout is unpaid.
- **A rejected or cancelled principal-withdrawal voucher makes the investment Active again.** Until now, only moving that voucher to trash did this. The withdrawal date, voucher link and note stay as history, and the investment can be withdrawn again with a new voucher.
- Confirmed with the owner: an **approved** voucher still can't be moved to trash.

**Technical Notes:**
- New `VoucherLinkedPayoutsService` (`voucherTrashed()` / `voucherRestored()`), called by `VoucherTrashService`. The voucher link on the payout is kept so a restore can find it. A restore does nothing if the voucher was rejected or cancelled.
- `VoucherService::reject()` and `cancel()` call `InvestmentWithdrawalService::voucherVoided()` (renamed from `voucherTrashed()`). New `Voucher::isVoid()` (rejected or cancelled). The `Investment` model counts "withdrawn" only while its voucher exists and isn't void.
- `ProjectSettlement` allows one step back, from `paid_out` to `confirmed`, and only while one of its payouts is unpaid again. Otherwise its status still only moves forward.
- No migration.

## [2.35.0] - 2026-09-28

**Release type:** Minor Feature Update

### Added

- **In-app Documentation.** A new **Documentation** menu (visible to everyone signed in) is a step-by-step guide hub for the app itself, browsable by category with a search box. Admins with the Settings permission write and organize the guides from **Settings → Documentation** — a rich-text editor per article, grouped into categories, with a Published toggle so drafts stay hidden until ready.

**Technical Notes:**
- New tables `documentation_categories` (`name`, `slug`, `icon`, `sort_order`) and `documentation_articles` (`documentation_category_id`, `title`, `slug`, `content`, `sort_order`, `is_published`) — migration `2026_09_28_140000_create_documentation_tables`. Not company-scoped: the same guides show for every company, since they document the app, not business data.
- `DocumentationArticleResource` (Settings cluster, `canManageSettings()` gated) manages articles; categories are created inline from the article form. `Documentation` page (standalone, top-level nav, open to any signed-in user) lists published articles by category and renders content with `RichContentRenderer`.

- **Voucher trash.** A voucher is never deleted directly. **Move to trash** (on the voucher list and the voucher page) moves it to **Finance → Vouchers → Trash**. From there it can be **restored** or **deleted permanently**. Only users with the new **Voucher: Move to Trash, Restore and Delete Permanently** (`voucher.delete`) permission can do any of this. Super admins have it automatically; give it to other roles in user settings. The trash shows who moved each voucher and when.
  - An **approved** voucher can't be moved to trash, because its money has already been booked (expense, payment or account transaction).
  - If a principal-withdrawal voucher goes to trash, its investment becomes **Active** again. Restoring the voucher marks it **Withdrawn** again, and a permanent delete leaves it **Active**. The withdrawal date and note are kept.

### Changed

- **Settlements leave withdrawn investments out.** A settlement calculated after an investor withdrew their principal no longer includes that investment, so it gets no profit share and its principal isn't paid out again. Settlements made before the withdrawal are not changed.

**Technical Notes:**
- New migration `2026_09_28_140000_add_soft_deletes_to_vouchers_table` adds `deleted_at` (indexed) and `deleted_by` (FK users, `nullOnDelete`). Run `php artisan migrate --force`. Existing vouchers are unaffected.
- `Voucher` uses `SoftDeletes`, so all Eloquent queries (lists, reports, sums) skip trashed vouchers. `nextVoucherNumber()` also checks trashed vouchers, so a number is never reused. Every FK to `vouchers` is `nullOnDelete` or `cascadeOnDelete`, so a permanent delete is safe. Attachment rows cascade; their files are left in storage.
- New `VoucherTrashService` (`trash` / `restore` / `deletePermanently`), new `User::canDeleteVoucher()`, and a new page `TrashedVouchers` at `/vouchers/trash`. `VoucherResource::canDelete()` stays `false`.
- `InvestmentWithdrawalService::voucherTrashed()` / `voucherRestored()`. The `Investment` model allows withdrawn → active only while the withdrawal voucher is in trash, and counts "withdrawn" only while that voucher exists. An investment that has ever had a withdrawal voucher cannot be deleted.
- `SettlementService::calculateAndSettle()` uses `investments()->active()`.

## [2.34.0] - 2026-09-28

**Release type:** Minor Feature Update

### Added

- **Investors who take their principal back are kept on record as "Withdrawn".** Each investment has a **Status**: **Active** or **Withdrawn**. Use **Withdraw principal** on the investment page, or in the investor's or project's Investments tab, after the investor has taken their money back. Enter the date, the fund source it was paid from, the payment method and reference, and an optional note.
  - **Fixed rule:** every withdrawal creates a pending **Investor Payout** debit voucher in **Finance → Vouchers** for the full amount, and the investment turns **Withdrawn** only once that voucher exists. If the voucher can't be created, for example because the fund source has no linked account, nothing changes.
  - A withdrawn investment can't be deleted or edited, and can't be withdrawn twice. Its page shows the withdrawal date, the voucher (with a link) and its status.
  - The investor list shows each investor's **Status**: **Active** while any investment is active, **Withdrawn** once all of them are withdrawn. There is a **Status** filter, and investors are never deleted.

**Technical Notes:**
- New migration `2026_09_28_130000_add_withdrawal_status_to_investments_table` adds `status` (default `active`, indexed), `withdrawn_at`, `withdrawal_voucher_id` (FK `vouchers`, `nullOnDelete`) and `withdrawal_note`. Existing investments become `active`. Run `php artisan migrate --force`.
- New `InvestmentWithdrawalService::withdraw()` locks the investment, calls `VoucherService::submit()` (debit, `investor_payout`, fund source's account), then sets the status, all in one transaction. The `Investment` model refuses `status = withdrawn` without a withdrawal voucher, and refuses to change or delete a withdrawn investment.
- `Investor::investmentStatus()` works it out from the investments. Nothing is stored on the investor.
- Settlement calculations are unchanged: withdrawn investments are still counted if the project is settled later. This is left for the owner to decide.

## [2.33.0] - 2026-09-28

**Release type:** Minor Feature Update

### Added

- **Filter the investor list by project.** The investor list has a **Project** filter (pick one or more projects) and a **Projects** column showing which projects each investor has invested in. There is also a **Channel Partner** filter.
- **Select many investors at once.** The investor list now has checkboxes. **Assign channel partner** sets the channel partner for all selected investors who don't have one yet. Investors who already have a channel partner are left unchanged and counted in the message; change those one by one from their edit page (that still needs the channel-partner permission and a reason).
- **Channel partner when importing.** The **Import Excel / CSV** and **Paste text** dialogs have a **Channel partner for this import (optional)** section. The chosen partner is set on every new investor in that import. A row that names its own channel partner keeps it, and investors who already exist are not changed. The drafts bulk action is now **Set project / payment / channel partner**.
- **Upload NID, nominee NID, signed contract (stamp paper) and cheque copies on the investor form.** The create and edit forms have a **Paper copies** section with one upload box for each. The files go to the investor's Documents tab in private storage. Only users with `investments.manage` see this section.

### Changed

- **Removed the "Upload documents by NID" button** from the investor list. It matched files to investors by the file name only, with no way to pick the investor or the document type. Use the upload boxes on the investor form instead.

**Technical Notes:**
- `InvestorResource::PAPER_COPY_UPLOADS`, `splitPaperCopies()`, `attachPaperCopies()`; `CreateInvestor::handleRecordCreation()` and `EditInvestor::handleRecordUpdate()` save the uploads as `InvestmentDocument` rows in the same transaction as the investor.
- `InvestorImportService::investmentDefaults()` also accepts `channel_partner_id` (checked against the current company).
- Deleted `InvestorDocumentUploadService` and the `document_keywords` config. No migration.

## [2.32.0] - 2026-09-28

**Release type:** Minor Feature Update

### Added

- **Quote status updates for connected websites.** When staff change the status of an inquiry that a connected website sent in (for example Tasneem Knit or Noor Solar), the website is notified straight away, so its customers can see where their quote stands. Only inquiries the website itself sent are reported.
- **No more duplicate inquiries from website retries.** A website can now send its own quote number with an inquiry. If the same inquiry is sent again (for example after a timeout), the ERP returns the one it already has instead of adding a second lead.

### Technical Notes

- **Migration:** `2026_09_28_120000_add_external_reference_to_leads_table` adds nullable `leads.external_reference` with a unique `(company_id, external_reference)` index. It is additive.
- **API:** `POST /api/v1/leads` accepts optional `external_reference` (max 190). A repeat returns the existing lead with `200`; the response now includes `external_reference`.
- **Webhook:** new `lead.status_updated` event (`id`, `external_reference`, `status`, `updated_at`) via `WebsiteWebhookDispatcher::dispatchLeadStatusUpdated()`, fired from `Lead::updated` when `status` changes and `external_reference` is set. Same signing, queue job and per-company settings as the existing events.
- **Docs:** `docs/api/website-integration.md` and `website-integration.openapi.yaml` updated. New `docs/api/website-mapping-tasneem-noor.md`: a path-by-path, field-by-field mapping from each website team's own (incorrect) integration doc to the real API, linked from `website-integration.md`.
- No new user-visible strings, so no `lang/` changes.

## [2.31.0] - 2026-09-28

**Release type:** Minor Feature Update

### Added

- **The NID / passport number is now the investor ID.** A phone number can change; the NID does not, so every investor is tracked by their NID / passport number:
  - It is required when adding an investor, and one number can belong to only one investor. Spaces, dashes and Bangla digits don't matter: `1990 1234-56789` and `১৯৯০১২৩৪৫৬৭৮৯` are the same number.
  - The investor list shows it as **Investor ID (NID / Passport)**, and you can search the list by it.
  - Excel/CSV/text import recognises returning investors by NID, even if their phone number has changed. The draft says when the phone in the file differs from the saved one.
  - A new **NID missing** filter lists older investors who don't have an NID yet.
- **Paper copies of NID cards and contracts.**
  - New document types: investor's NID / passport copy, nominee's NID / passport copy, project contract (same for all investors), cheque copy and stamp copy.
  - Upload them on the investor's **Documents** tab.
  - A project's contract is uploaded **once on the project**, and it covers every investor in that project.
  - **Upload documents by NID** on the investor list takes many scans at once. Name each file with the investor's NID and what it is, for example `1479939603_nid.jpg`, `1479939603_nominee.jpg`, `1479939603_contract.pdf`, `1479939603_cheque.jpg`, `1479939603_stamp.jpg`. Each file is attached to the investor with that NID. Files whose NID matches no investor are listed.
  - Each investor has a **Paper copies** checklist, shown on their page and as a column in the list: NID copy, nominee's NID copy (when there is a nominee), and a contract for every project they invested in. The **Paper copies missing** filter shows who still has something missing.

### Fixed

- **Investor Drafts list now shows NID, address, nominee NID, nominee phone, stamp no. and cheque no.** These were already being read from the uploaded file and saved on the draft, but the list table had no columns for them, so an import looked like it had skipped those fields. NID is shown next to Phone by default; the other five are available via the column-toggle button (hidden by default, same as the existing Father/Spouse and Nominee Name columns).

### Security

- **Only users with the `investments.manage` permission can see or download NID copies, contracts, and cheque and stamp copies.** Users who can only view investments no longer see these files in any Documents tab, and the download link refuses them. This also covers the contract file on an investment's security instrument. Other investment documents, such as settlement sheets and receipts, stay visible with `investments.view`.

**Technical Notes:**
- New migration `2026_09_28_110000_add_nid_hash_to_investors_and_investor_drafts`: adds `nid_hash` (keyed HMAC of the normalised number, since `nid_number` is encrypted), with an index on `(company_id, nid_hash)`. Existing rows are backfilled. Run `php artisan migrate --force`. Uniqueness is enforced by the models, not a DB unique index, so the deploy never fails on old duplicate data.
- `App\Support\InvestorIdentity` (normalize/hash), `InvestorDocumentUploadService`, `InvestmentDocument::SENSITIVE_CATEGORIES`, `Investor::documentChecklist()` / `scopeMissingDocuments()` / `scopeWhereNid()`. The file-name keywords live in `config/investor_import.php`.
- Existing investors without an NID keep working, but saving them from the edit form now requires one.

## [2.30.0] - 2026-09-28

**Release type:** Minor Feature Update

### Added

- **Reseller portal foundation.** Approved resellers (and applicants) now have their own login and dashboard, separate from the main storefront and the admin panel: `reseller.<company-domain>` (or a fully custom domain set in Company Management, for a company that wants one). Only that address opens the portal — every other page on it redirects there, and the portal itself is unreachable from the main shop or `/admin`.
  - **Become a reseller** (footer/menu link, and the "Your Store" account page) now opens the portal's own apply form instead of the old in-storefront page: name, phone, business name, an optional note, and a password — approval alone is then enough to log in, no separate storefront account needed.
  - A pending applicant can log in right away and sees a plain "under review" page; a rejected one is kept out, with the admin's note shown if there is one, instead of the dashboard.
  - The dashboard pages themselves (overview, orders, products, earnings, withdrawals, store settings, profile) are described in the entries below.
- **Reseller portal: Overview and Orders.** An approved reseller's dashboard (`reseller.<domain>/portal`) now shows: available to withdraw, orders waiting for delivery, this month's orders/sales/commission, a store-link card (**Copy link**, **Share on WhatsApp**, **Share on Facebook** — with a banner instead if the store has no link yet), a 6-month commission chart, and the last 5 orders. A new **Orders** page lists every order credited to the reseller — date, customer (full name, phone and address — the owner's decision, 2026-09-28), items, sale amount, status, and commission — with status and date-range filters, search by order number, and a read-only detail view (items, timeline, commission). Resellers never edit an order from here.
- **Reseller portal: Products & pricing page.** An approved reseller's portal now has a full **Products** page (`reseller.<domain>/portal/products`): the company catalog with an **In my store** switch per product, **Suggested price**, **Your cost**, an editable **Your price** (never below Your cost), and the resulting **Commission per unit**. Filters for category, in/not in store, and no wholesale rate. Bulk **Add to store**, **Remove from store**, and **Set price = suggested + %** (skips anything not already in the store or without a wholesale rate). Each product also has **Copy link**, **Share on WhatsApp**, and **Download photos** (zips the product's featured image and gallery for posting).
- **Reseller portal: Earnings, Withdrawals, My Store and Profile.** The rest of the reseller's own dashboard (`reseller.<domain>/portal`):
  - **Earnings** — every commission (one per order) with status (Pending delivery / Payable / Cancelled) and date-range filters, search by order number, and a **Monthly totals** table for the last 12 months. Cancelled and returned orders are not counted.
  - **Withdrawals** — the balance cards, a **Request withdrawal** form (the store's minimum and the reseller's available balance are enforced, the same as on the old "Your Store" page), and the full history with each request's status and transaction reference — or the store's reason if it was rejected.
  - **Saved payout accounts** — bKash / Nagad / Rocket numbers and bank details saved once and picked from a list when requesting a withdrawal, with an optional label and one default account. The first account saved becomes the default; ticking **Save this account for next time** on the withdrawal form saves it too. Changing or deleting a saved account never changes a request already sent.
  - **My Store** — change the store's URL name (a warning says links already shared will stop working) and the **Store name** shown at the top of the store.
  - **The store header shows only the reseller's own name.** Inside a reseller's store (`/store/{slug}`) the header now carries the reseller's **Store name** (or, if empty, their business name) instead of the company's name and logo — the owner's decision (2026-09-28). The company's own store is unchanged, and so are the footer and page title.
  - **Profile** — name, email, and a password change (needs the current password). The phone number is the login, so it is read-only.
- **Reseller custom domains.** A company can switch on **Reseller custom domains enabled** (Company Management, Super Admin only, off by default). Approved resellers can then enter their own domain (e.g. `myshop.com`) on the portal's **My Store** page. The page shows the DNS record to add (a CNAME to the company's domain, or an A record with the same IP); **Check DNS** confirms it points to the server and makes the domain live — only that reseller's store opens there, and the portal never does. Changing the domain means checking again. Domains already used by the platform (company domains, anything under them, portal domains) or by another store are refused. A new `/internal/tls-ask` endpoint tells an on-demand-SSL proxy (Caddy) which domains may get a certificate — only verified reseller domains. The proxy itself is a server task (`docs/deployment.md`).
- **Reseller store subdomains.** A company can switch on **Reseller subdomains enabled** (Company Management, Super Admin only, off by default). Then every approved reseller can pick a **Store subdomain** in the portal's **My Store** page, and their store opens at `name.<company-domain>` — only that reseller's products, prices and attribution, with the cart and checkout staying on the same address. Names are lowercase letters, numbers and dashes (3–40 characters), unique per company, and a reserved list (`www`, `reseller`, `admin`, `portal`, …) is refused. The portal itself is never reachable on a store subdomain, and `/store/{slug}` there only opens the subdomain's own reseller. Switching the company off makes the subdomains stop working and store links fall back to the long `/store/{slug}` form. The server needs a wildcard DNS record and wildcard SSL certificate first (`docs/deployment.md`).
- **The old "Your Store" page now opens the reseller portal.** An approved reseller who opens **My Account → Your Store** (or its sidebar link) is taken to their portal at `reseller.<company-domain>/portal` — the portal has its own sign-in, so they sign in once there. The staff preview keeps the old page, since it has no portal address. `docs/deployment.md` has a new **Reseller Portal Domain** section (DNS record, Coolify domain, `npm run build`).
- **Reseller prices and commission.** An approved reseller now sets their own selling price for each product in their store (**My Account → Your Store**):
  - **Your cost** = the product's lowest wholesale tier price + one **Reseller cost per unit** the store sets once, the same for every product (**Storefront Settings → Reseller Settings**, a new tab in the settings sidebar).
  - **Suggested price** = the product's normal selling price. Leaving the price blank sells at the suggested price.
  - Any price at or above **Your cost** is allowed. The difference is the reseller's commission.
  - Products without wholesale tiers, and products with variants, sell at the normal price and earn no commission.
- **Customers of a reseller pay the reseller's price.** Once a visitor opens a reseller's store link (`/store/{slug}`), they see that reseller's prices for the reseller's products on the whole storefront until their session ends, and the order is credited to that reseller. The wholesale price table is hidden from them, because it shows the reseller's own buying rate.
- **Commission becomes payable when the order is delivered.** If the order is cancelled, returned or refunded, the commission is cancelled. Resellers see money available to withdraw, money waiting for delivery, money being paid out, their total withdrawn, and recent commissions on their store page.
- **Withdrawal requests.** A reseller asks for a withdrawal (bKash / Nagad / Rocket / bank, with their account number). They cannot ask for more than they have available, or less than the **Minimum reseller withdrawal** set in **Storefront Settings → Reseller Settings**. Staff handle requests under **Resellers → Withdrawal requests**: **Approve**, **Mark paid**, or **Reject** (with a reason).
- **Paying a withdrawal books it in Finance automatically.** **Mark paid** asks for the transaction ID, which fund source the money was paid from, and an expense category (both are pre-filled with the ones used last time). It creates a pending debit voucher in **Finance → Vouchers**. Once the voucher is approved there, the payout is recorded as an expense and deducted from that account. If the voucher can't be created (for example, the fund source has no linked account), the request is not marked paid. The voucher number shows on the withdrawal request. A rejected request returns the money to the reseller's available balance. **Resellers → Commissions** lists every commission.

- **A reseller's store links stay inside their store.** Opening a product, category, cart, or the store's own home from inside `/store/{slug}` used to send the visitor to the main shop's page instead — the price and commission still worked (kept in the session), but the visitor left the reseller's curated catalog. Every such link inside a reseller store now stays under `/store/{slug}`, across every storefront theme. Checkout, order tracking, and account pages are unaffected — they still work through the same shop-wide session, as before.

### Security

- **The audit log no longer records a reseller's id as a staff user.** On the reseller portal the default login guard is the customer guard, so the audit log's "who" was being filled with a reseller's id — a database error at best, another person's name in the log at worst. Changes made from the portal are now logged without a staff user attached; staff actions are unchanged.
- **Reseller application preview is limited to your own company.** A signed-in staff member could open another company's reseller application page in preview (`/storefront/{company}/reseller`), and could even submit an application there. The preview now returns "not found" unless the staff member belongs to that company. Super admins are not affected. This uses the same check as the storefront preview.

### Technical Notes

- **Migrations:**
  - `2026_09_28_110000_add_reseller_pricing_columns` adds nullable `reseller_products.price`, nullable `order_items.reseller_base_price`, and `storefront_settings.reseller_cost_per_unit` / `reseller_min_withdrawal` (default 0).
  - `2026_09_28_110100_create_reseller_commissions_table` (one row per order).
  - `2026_09_28_110200_create_reseller_payout_requests_table`.
  - All are additive. Existing orders get no commission rows.
- **Pricing:** new request-scoped `ResellerPricing` (registered with `scoped()`).
  - `ResolveResellerFromSlug` remembers the reseller in the session, and `ResolveCompanyFromDomain` restores it on every storefront request. The storefront's own links point at main-store routes, so the URL alone could not carry the reseller.
  - `Product::selling_price` / `normalizedTiers()` ask it; `baseSellingPrice()` returns the raw price. Nothing outside a storefront request activates it.
  - The sale price is never below the base price, even if tier prices rise after the reseller set their price.
- **Orders and commissions:**
  - The cart freezes `reseller_base_price` onto each reseller-priced order item (COD path and the online-payment snapshot).
  - `StorefrontCart::orderResellerId()` credits the order to the reseller who priced it.
  - `ResellerCommissionService::sync()` runs from `Order::saved` and `OrderItem::saved` / `deleted`. Payable = `delivery_status = delivered` or `status = completed`; cancelled = cancelled/returned/refunded.
  - Available balance = payable commission − paid − open requests. It can go below zero if a delivered order is later returned after a withdrawal, and later earnings cover it.
  - Withdrawal requests take a per-reseller cache lock.
  - New `ResellerPayoutService::markPaid()` locks the request, submits a `business_expense` debit voucher through `VoucherService::submit()` (fund source's account, chosen expense category, reseller's method as `payment_method`, transaction ID as `transaction_id`), then marks the request paid with `voucher_id`. It all happens in one transaction, and unlike the investment payouts a voucher failure is not swallowed. `reseller_payout_requests.voucher_id` (nullable FK, `nullOnDelete`) is part of the new table's migration.
- **Models and permissions:** new `ResellerCommission` and `ResellerPayoutRequest` models (`BelongsToCompany`, added to `MultiCompanyIsolationTest`, `User::MODEL_MODULES` → `sales`). New Filament resources `ResellerCommissionResource` (read-only) and `ResellerPayoutRequestResource`, in the Resellers cluster, gated like `ResellerResource`.
- **Security fix:** `ResellerController::previewStorefront()` gains the `canAccessCompany()` gate. `ResellerStoreController`'s preview is already limited to the signed-in reseller customer's own company.
- **Settings page:** new `reseller` tab in `HasStorefrontSettingsNavigation`, with `'reseller'` inserted into `StorefrontSettingResource::$sectionKeys` (sections are mapped to tabs by position).
- **Translations:** new strings go through `__()`; 71 keys added to `lang/en.json` + `lang/bn.json`.
- **Store-link fix (13_RESELLER_PORTAL_PLAN.md, Phase 1):** new `App\Support\StorefrontUrl::route($family, $params, $previewSlug)` resolves a storefront route name to the staff-preview family (`storefront.preview.*`, when `$previewSlug` is passed), then the reseller-store family (`storefront.reseller_store.*`, when the request carries a `storefront_reseller` attribute — set only by `ResolveResellerFromSlug` — for the 10 families that have one), then the plain main-store family. Checkout, account, offers, tracking, contact and complaint pages have no reseller-store route yet, so they intentionally keep resolving to the main-store URL — `ResellerPricing`'s session-remembered reseller already keeps price/commission correct there. A new `storefront.reseller_store.show` route (`GET /store/{resellerSlug}`) was added as the reseller store's home. Replaced the `isset($previewSlug) ? route('storefront.preview.X', ...) : route('storefront.X', ...)` pattern with this helper across the shared layout, every storefront theme's homepage, and all product/category/cart/checkout/account/offer partials and pages (57 call sites, no user-visible text changed). `CartController::redirectToCheckout()` now also resolves through the helper. New `StorefrontResellerLinkScopeTest` crawls the rendered HTML of every reseller-store page across all 3 registered themes and asserts every product/category/cart/checkout link stays under `/store/{slug}`.
- **Reseller portal (13_RESELLER_PORTAL_PLAN.md, Phase 2 — portal foundation):**
  - Migration `2026_09_28_120000_add_reseller_portal_domain_to_companies_table` adds nullable unique `companies.reseller_portal_domain`, admin-managed (Company Management, super admin only), normalised the same way as `companies.domain`.
  - `Company::forResellerPortalHost($host)` (the DB lookup a request's host resolves against) and `Company::resellerPortalHost()` (the forward direction, used to build the "Become a reseller" link) are the single source of truth for the rule: `reseller_portal_domain` if set, else `"reseller."` + `domain`, only for a company with `reseller_module_enabled`. Both are guarded with `Schema::hasTable('companies')`, since the first is called from global middleware that runs on every request, including ones in tests with no `companies` table.
  - New `App\Providers\Filament\ResellerPanelProvider` registers a second Filament panel (`id: 'reseller'`, `authGuard('customer')`), at the path `/portal` on **every** host — Filament's own `->domain()` only matches one fixed hostname, which can't express "any company's domain, prefixed with reseller.", so host resolution is done by this app's own middleware instead, exactly as the main storefront already does for company domains.
  - New `App\Http\Middleware\ResolveCompanyFromResellerPortalDomain` (in the panel's own middleware, mirrors `ResolveCompanyFromDomain`) puts the portal's company in `CompanyContext`; `App\Http\Middleware\RestrictResellerPortalHost` (global `web` middleware, and also in `AdminPanelProvider`'s own stack, since the admin panel doesn't route through the `web` group) redirects every non-`/portal` path to `/portal` on a portal host, and 404s `/portal` on every other host — this is what keeps the admin panel and the portal off each other's hosts.
  - `Customer` now implements `FilamentUser` + `HasName`. `canAccessPanel()` allows a pending or approved reseller belonging to the host's resolved company (rejected, inactive, or a different company all fail — a wrong-company or rejected reseller gets a 403 from Filament's own `Authenticate` middleware, never a 200).
  - New `App\Filament\Reseller\Pages\Auth\Login` reimplements `authenticate()` on top of the existing `CustomerAccountService::attemptLogin()` (phone **or** email + password, same rate limiting) instead of Filament's own email-only `EloquentUserProvider` lookup. New `App\Filament\Reseller\Pages\Auth\Apply` (`->registration()`) reuses `ResellerController::apply()`'s exact business rule (match by phone, never downgrade an already-approved reseller) and additionally sets a password, so approval alone is enough to log in.
  - New `App\Filament\Reseller\Pages\Dashboard` is the panel's only page for now — a pending/rejected/approved-with-placeholder status page. Full dashboard pages (orders, products, earnings, withdrawals) are Phase 3–5.
  - Password reset inside the portal is not in this phase — a reseller who forgets their password can still reset it from the main storefront's forgot-password page (same account, same `customers` table).
  - New `ResellerPortalTest`: host isolation both directions (portal path 404s off-host, admin panel redirects away from the portal host, root of the portal host redirects to `/portal`), a disabled reseller module 404s its would-be portal host, a custom `reseller_portal_domain` replaces (not adds to) the `reseller.` default, cross-company access is a 403 (never the other company's data), pending vs. rejected vs. approved each see the right page, and applying creates the pending `Customer` with a hashed password.
  - **Translations:** 12 new keys in `lang/en.json` + `lang/bn.json` (portal/apply strings). `Company` admin-resource labels are plain English, unwrapped in `__()`, matching every other label already in `CompanyResource.php` (a super-admin-only screen).
- **Reseller portal (13_RESELLER_PORTAL_PLAN.md, Phase 3 — Overview + Orders):** owner's answers to the two remaining §2.1 questions gathered first via `AskUserQuestion` (full customer info shown to resellers; the reseller's own name/brand only, no company branding, on the store page — the latter feeds Phase 5, not used yet). Recorded directly in the plan file.
  - `Dashboard`'s "coming soon" placeholder (approved branch only; pending/rejected status pages are unchanged) replaced with: `ResellerCommissionService::balances()` for the stat cards (already existed, reused as-is); `waitingForDeliveryCount()` (`Order::ACCOUNTED_STATUSES` minus delivered/returned/cancelled/failed delivery statuses — no existing scope for this, built fresh); `thisMonth*()` methods filtered on `order_date >= startOfMonth()`; `recentOrders()` (`resellerOrders()->latest('order_date')->limit(5)`); the store-link card reuses `ResellerStoreController::storeUrl()`'s exact `'https://'.$company->domain.route(...)` construction, with a warning banner instead when `reseller_slug` is still blank.
  - New `App\Filament\Reseller\Widgets\CommissionTrend` (`Filament\Widgets\ChartWidget`, same shape as the existing `SalesPurchaseTrend`), grouping `ResellerCommission.amount` by month of `created_at` (not `payable_at`, which is null for a still-pending commission and would otherwise vanish from the chart until delivery) via the same DB-portable `date_format`/`to_char`/`strftime` pattern `ReportService` already uses. Embedded directly in the dashboard's Blade view via `@livewire()`, since this page is a plain `Filament\Pages\Page`, not Filament's own `Dashboard` page class, so it has no built-in widgets slot.
  - New `App\Filament\Reseller\Pages\Orders` (same standalone-table shape as `Products`), scoped to `Customer::resellerOrders()`. Columns: order number, date, **full** customer name/phone/address (`Order::customer`, not the denormalized `customer_name`), item count, sale amount, a status badge (`Order::workflowStage()` — the same derived "Confirmed/Processing/Shipping/Delivered/Completed/Cancelled/Returned/Refunded" value the admin's own order-workflow UI is built around, falling back to the raw `status` label for `draft`, which `workflowStage()` can return but `WORKFLOW_STAGES` has no label for), and commission (amount + `ResellerCommission::STATUSES` label, or an em dash when the order has no reseller-priced lines). Filters: `status` (`Order::STATUSES`), and a from/until date-range `Filter` on `order_date` (no existing date-range filter pattern in this codebase to copy — built fresh with two `DatePicker`s). A row action opens a read-only detail modal (`->modalContent()` + a plain Blade partial: items, totals, commission, and the order's `statusTransitions` as a timeline) — resellers never get an edit/status-change action.
  - New `ResellerPortalDashboardTest`: the dashboard renders end-to-end over real HTTP for an approved reseller (proving the embedded chart widget renders too, not just the page shell), the Orders page is scoped to the reseller's own company (never another reseller's orders), full customer info and the synced commission both show correctly, and a pending/rejected reseller's `Orders::canAccess()` is false.
  - **Translations:** 14 new keys in `lang/en.json` + `lang/bn.json`; several column/stat labels (Order, Date, Customer, Phone, Address, Items, Subtotal, Name, Available to withdraw, Waiting for delivery, Your price) were already present from earlier phases and reused as-is rather than duplicated.
- **Reseller portal (13_RESELLER_PORTAL_PLAN.md, Phase 4 — Products & pricing):**
  - New `App\Filament\Reseller\Pages\Products` (`Filament\Pages\Page implements HasTable`, following the same standalone-table shape as `Products\Pages\BulkUpdateStock`), registered as the panel's second page. `canAccess()` requires an approved reseller — a pending or rejected applicant gets a 403 if they reach the URL directly, same as Filament's own panel-level check.
  - Reuses the exact business rules already established for the storefront's own reseller price page (`ResellerStoreController`/`ResellerPricing`), not new ones: "in store" toggling creates/deletes the `reseller_products` row exactly like `ResellerStoreController::toggleProduct()`; "Your price" is rejected below `ResellerPricing::basePrice()` exactly like `ResellerStoreController::updatePrice()`; a price can only be set once a product is picked (matches the existing `firstOrFail()` behaviour). Picks/prices are loaded once per request into Livewire state (`$picks`) and reloaded after every write, so every cell stays consistent without a per-cell query.
  - "Set price = suggested + %" computes `suggested_price * (1 + percent / 100)`, floored at the base price, and silently skips (with a count in the notification) any selected product not already in the reseller's store or without a wholesale rate — it never adds a product to the store on its own.
  - New `App\Http\Controllers\Reseller\ResellerProductPhotosController` streams a zip of a product's `image` + `gallery_images` (via `CompanyStorageService::locatePublic()`). Registered at `/portal/products/{product}/photos`, guarded by `auth:customer` + `ResolveCompanyFromResellerPortalDomain` (applied explicitly, since this is a plain route outside the Filament panel's own middleware stack) and an explicit company-match check in the controller; the `/portal` path prefix is what lets `RestrictResellerPortalHost`'s allow-list pass it through on the portal host.
  - "Copy link" uses the existing `App\Support\ClipboardCopy::alpineHandler()` (already used elsewhere for copy-to-clipboard actions); "Share on WhatsApp" opens `https://wa.me/?text=...` (a generic share, not `PhoneLinks::whatsappUrl()`, which needs a specific recipient's number).
  - New `ResellerPortalProductsTest`: catalog is scoped to the portal's own company, in-store toggling creates/deletes the pick row, price floor enforcement, bulk add/remove, bulk set-price-from-suggested skips unpicked/no-rate products, and the photo-download route 404s a product from a different company.
  - **Translations:** 16 new keys in `lang/en.json` + `lang/bn.json`.
- **Reseller portal (13_RESELLER_PORTAL_PLAN.md, Phase 5 — Earnings, Withdrawals, My Store, Profile):**
  - **Migrations (additive):** `2026_09_29_100000_add_reseller_store_name_to_customers_table` (nullable `customers.reseller_store_name`) and `2026_09_29_100100_create_reseller_payout_accounts_table`. New `ResellerPayoutAccount` model (`BelongsToCompany`, added to `MultiCompanyIsolationTest`), `Customer::resellerPayoutAccounts()`, `Customer::resellerStoreName()` (`reseller_store_name` → `business_name` → `name`) and `Customer::resellerStoreUrl()` (the Dashboard now delegates to it).
  - **Shared payout logic:** the withdrawal request (per-reseller cache lock, balance re-check) moved out of `ResellerStoreController::requestPayout()` into `ResellerCommissionService::requestPayout()`; the storefront page and the portal both call it, so the rule cannot drift apart.
  - **Pages** (`App\Filament\Reseller\Pages`, all `canAccess()` = approved reseller through the new `ForApprovedResellers` trait): `Earnings` (table over `ResellerCommission`; monthly totals grouped with the new DB-portable `ResellerCommission::monthExpression()`, cancelled excluded), `Withdrawals` (balance cards + history table; the request form is a header `Action` modal — `minValue`/`maxValue` validation, then the locked re-check in the service), `PayoutAccounts` (table + add/edit/delete/make-default; `store()` updates an existing method + details instead of duplicating it), `MyStore` (slug rules identical to the old page: `alpha_dash`, unique within the company, ignoring the reseller's own row), `Profile`.
  - **Profile security:** a new password needs the current one (`Hash::check`); wrong attempts are throttled with `RateLimiter` (5 per 15 minutes per reseller); the session's `password_hash_customer` is refreshed (as Filament's own `EditProfile` does) so the change does not log the reseller out; profile and password changes are recorded in `storefront_customer_activities`, like the storefront profile page. An email already used by another login account, or one that fails `EmailFormat::PATTERN`, is refused as a form error (the model's own `saving` check would otherwise throw a `ValidationException` with a key the form cannot show).
  - **Store header (§2.1 Q3):** `storefront/layout.blade.php` derives `$brandName` / `$brandLogoUrl` / `$brandLogoDarkUrl` from the `storefront_reseller` request attribute (reseller's name, no logo) or the company; both header partials (`partials/layout/header` and `themes/marketplace-pro/...`) use them. `<title>`/meta and the footer are unchanged.
  - **`AuditLogService::actorId()`:** `audit_logs.user_id` is now filled only when the signed-in principal is a `User`. Filament's `Authenticate` middleware switches the default guard to `customer` on the portal, which made `Auth::id()` a reseller id (FK violation on MySQL, or a wrong attribution). Found by this phase's tests, which save a `Customer` from the portal.
  - **Fixes to earlier unreleased phases, found while building this one:** `CommissionTrend` no longer counts cancelled commissions (the service keeps `amount` on a cancelled row); the panel now has its own Vite theme (`resources/css/filament/reseller/theme.css`, registered in `vite.config.js` and `ResellerPanelProvider::viteTheme()`) — Filament's precompiled stylesheet carries none of the generic Tailwind classes the portal's Blade views use (`grid-cols-*`, `justify-between`, `text-right`, …), and only the admin panel had a theme that scanned `resources/views/filament/**`. **`npm run build` is needed at deploy** (`public/build` is not committed). Navigation labels for Dashboard / Orders / Products are now translated and every page has a `navigationSort`.
  - **Translations:** 39 new keys in `lang/en.json` + `lang/bn.json`, including four that Phase 3 already used but never added (`From`, `Until`, `Orders`, `Commission (last 6 months)`).
  - New `ResellerPortalAccountTest` (25 tests): scoping of earnings, withdrawals and saved accounts to the signed-in reseller, minimum/maximum/lock enforcement, default-account rules, slug uniqueness per company, password change/throttle, the audit-log actor, HTTP rendering of every new page, and the store header for every registered theme (with mutation checks confirming the header, chart, audit and balance tests fail when the code they cover is broken).
  - **Phase 6 — switch-over:** `ResellerStoreController::edit()` redirects (`redirect()->away()`) an approved reseller to `Company::resellerPortalHost()` + `/portal` when the company has one, and the account layout's "Your Store" link points there. The POST/PATCH endpoints of the old page are left in place (still company- and reseller-scoped) and staff preview is unchanged. `PROJECT_GUIDE.md` and `docs/deployment.md` document the portal and its DNS/Coolify steps. Tests in `ResellerSelfServiceTest` / `ResellerCommissionTest` now expect the redirect.

## [2.29.0] - 2026-09-28

**Release type:** Minor Feature Update

### Added

- **Investor drafts can now record the investment too.** When importing an Excel/CSV file or pasted text, open **Investment for this import (optional)** and pick the **project**, **payment method** and **investment date**. The **বিনিয়োগ (৳)** amount of each row is read into its draft. When the draft is confirmed, the investment is added to that project, and the stamp and cheque numbers are saved as its security instrument. If the project receives money into a fund source, the pending capital voucher is created too, the same as when an investment is added by hand.
  - Each draft's review page has an **Investment** section, where project, amount, payment method and date can be changed.
  - **Set project / payment** sets these for many selected drafts at once.
  - A draft with an amount but no project shows **Needs fixing** until a project is picked or the amount is cleared. A closed or settled project cannot take new investments.
- **Returning investors get only the new investment, not a duplicate.** When a row's phone number matches an investor who already exists, the draft is linked to that investor automatically, and a note says so. Confirming adds only the new investment to them. Their name, NID, nominee and other details are not changed. On the review page, **Add to an existing investor** links or unlinks a draft by hand.

**Technical Notes:**
- New migration `2026_09_28_100000_add_investment_fields_to_investor_drafts_table` (`existing_investor_id`, `project_id`, `investment_amount`, `payment_method`, `invested_at`). Run `php artisan migrate --force`.
- `InvestorImportService::confirm()` creates the `Investment` (+ `InvestorSecurityInstrument` when stamp/cheque numbers exist) in the same transaction as the investor, then calls `InvestmentLedgerService::recordInvestmentReceived()`. The `Investment` model's own rules (investment window, one channel partner per project) still apply and block the confirm with their message.

## [2.28.1] - 2026-09-27

**Release type:** Patch/Fix Update

### Fixed

- **Image Generation works with OpenRouter.** On **Settings → Integrations → Image Providers**, **Provider type** now has an **OpenRouter (unified image API)** option. Before, an OpenRouter key could only be added as **Custom**, and every image, including "Start from an image", failed with "Custom image provider rejected the request (HTTP 404): Not Found". To fix an existing profile, change its **Provider type** to **OpenRouter** and save. The Base URL (`https://openrouter.ai/api/v1`) and model can stay as they are.

### Technical Notes

- New `OpenRouterImageProvider` adapter, registered in `ImageProviderResolver` and `ImageProviderSettingsService::API_FORMATS`. It posts to OpenRouter's single `POST /api/v1/images` endpoint (appending `/images` when the Base URL is the bare API root), sends `aspect_ratio`/`resolution`/`n`, and for image-to-image adds the source photo as a base64 data URL in `input_references` on the same call. The generic "Custom" adapter assumes OpenAI's `/images/generations` + `/images/edits` routes, which OpenRouter does not have.
- Production needs a running queue worker (`php artisan queue:work`) for Image Generation; without one, generations stay "Queued" forever.

## [2.28.0] - 2026-09-27

**Release type:** Minor Feature Update

### Added

- **Put an AI video on a product page or an offer page.** In **AI Tools → Video Generation**, a finished video now has two more buttons next to **Download**:
  - **Product** — pick a product. The video plays on the product page, under the photos, with the first photo as its cover. It replaces the product's current video, if it has one.
  - **Offer hero** — pick an offer / landing page. The video plays in the page's top banner, silent and on a loop, behind the headline. The offer's cover image stays as the video's first frame and as the picture used when the page is shared. Visitors whose device asks for less motion see the cover image instead.
  - Each video shows which product or offer it is attached to.
  - A video waiting for a reviewer's approval, or rejected, cannot be attached.
- **Remove a video from a product or offer.** When a product or offer has a video, its edit form shows the video with a **Remove video** link (Products → Product Images; Offers → the cover image section). Removing it takes it off the storefront. The clip stays in Video Generation, so you can attach it again. Videos cannot be uploaded here; they only come from Video Generation.
- **Video Library** (AI Tools → Video Library). Every video your company has made, in one table:
  - Search by prompt. Filter by purpose, status, who made it, review, favourites, attached to a product or offer, and saved to Google Drive.
  - Per video: **Play**, **Download**, **Reuse** (opens Video Generation with the same prompt, settings and starting image), and **Add to favourites**.
- **Favourite** button on each finished video in Video Generation. Starred videos can be filtered in the Video Library.
- **Save to Google Drive.** A finished video can be copied to your company's Google Drive folder from Video Generation or the Video Library. You get a notification when it is there, and the button turns into **Open in Google Drive**. The storefront still plays the video from the app's own storage; Drive only holds a copy.
  - Setup, by a super admin: the Google service account from **Settings → Backups → Google Drive Backup** is reused. Add the folder on **Settings → Integrations → Video Providers → Save to Google Drive** (paste the folder link) and share that folder with the service-account email shown there. Use a folder in a **Shared Drive**: Google may refuse uploads into a normal My Drive folder.
  - If Google refuses an upload, the video shows **Failed** with Google's reason, and you can retry.
  - Until the folder is set, the button is hidden.
- **Video Governance** (AI Tools → Tool Menu → **Video Governance** button, super admins only), the same controls Image Governance has:
  - **Monthly video cap per role.** 0 means unlimited; super admins are never capped; failed videos do not count. A user who has used their allowance is told so and nothing is generated. Video Generation shows how many videos are left this month.
  - **Approval before attaching.** Videos made by the chosen roles are marked "pending review" and cannot be put on a product or offer until a reviewer approves them in the Video Library (**Approve** / **Reject** with a reason). The creator sees the badge and the reviewer's note.
  - **This month's usage:** videos generated and estimated spend, by person and by provider.
  - New permission **AI Tools: Video Generation — approve others' videos for attaching** (`ai_tools.video_generation.review`), given to the built-in Manager role.
  - Everything is off until a super admin sets values.

**Technical Notes:** new `GeneratedVideoAttacher` (`attachToProduct()` / `attachToOffer()`) writes the stored clip path into `products.video_path` / `offers.video_path` (the columns came with the Phase 1 migrations) and sets the generation's `linked` morph. It refuses an unfinished row, a row whose clip is missing, a row awaiting or failing review, and a record from another company (`VideoAttachmentException`). `VideoGeneration` gains `attachToProduct` / `attachToOffer` actions, scoped to the user's own rows, and records `ai_video.attached` in the audit log. New read-only `AttachedVideoPreview` form field (never dehydrated, no upload) on `ProductForm` and `OfferForm`, with a `removeVideo` hint action. `storefront/products/show.blade.php` gains a `data-product-video` block; `storefront/offers/blocks/hero.blade.php` plays `video_path` over `cover_image` (`motion-reduce:hidden`). New strings go through `__()` with keys in `lang/en.json` and `lang/bn.json`. Plan: `12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md` Phases 3–4. Phase 4: new `VideoLibrary` page (AI Tools cluster, `ai_tools.video_generation`); `VideoGeneration` gains `toggleFavorite` / `saveToDrive` actions and `?from=<id>` prefill. New `SaveVideoToDriveJob` (queued, one try, sets/clears `CompanyContext`) → `VideoDriveExporter` → new `GoogleDriveBackupService::uploadStream()` (resumable upload, streamed from the public disk, `supportsAllDrives=true`); the backup `upload()` is unchanged. Per-company folder in `companies.settings->ai_tools->video_drive->folder_id`. **Migration:** `2026_09_27_100000_add_google_drive_columns_to_generated_videos_table` adds `drive_status`, `drive_file_id`, `drive_url`, `drive_saved_at`, `drive_error` to `generated_videos`. Audit event `ai_video.saved_to_drive`. Phase 5: new `VideoGovernanceService` (`companies.settings->ai_tools->video_governance`, cap = count of non-failed videos this month) and super-admin `VideoGovernance` page (hidden from the sidebar, linked from the Tool Menu header). `VideoGeneration::generate()` checks the cap before dehydrating the form and sets `review_status`; `VideoLibrary` gains `approveReview` / `rejectReview` (audit `ai_video.review_approved` / `ai_video.review_rejected`). `User::ROLE_PERMISSIONS['manager']` gains `ai_tools.video_generation.review`. **Migration:** `2026_09_28_100000_grant_video_review_permission_to_manager_role` appends that permission to the stored Manager role only if the row already has `ai_tools.video_generation`, leaving everything else unchanged.

## [2.27.1] - 2026-09-27

**Release type:** Maintenance Update

**Technical Notes:**
- CI workflow (`.github/workflows/deploy.yml`) moves to GitHub Action versions that run on Node.js 24: `actions/checkout@v5`, `actions/setup-node@v5`, `actions/setup-java@v5` and `actions/upload-artifact@v6`. GitHub is retiring the Node.js 20 runtime for actions, and the old v4 versions showed "Node.js 20 is deprecated" and "setup-java v4 is deprecated" warnings on every run. The runtime of each new version was checked in its `action.yml`: `upload-artifact@v5` still uses Node 20, so v6 is used. `shivammathur/setup-php@v2` already runs on Node 24. The Node version the project itself builds with (`node-version: 20.19.0`) is unchanged, and so are the tests, the release cut and the APK build steps.

## [2.27.0] - 2026-09-27

**Release type:** Minor Feature Update

### Fixed

- **Courier Fraud Check now works for Steadfast.** It uses the fraud-check endpoint in Steadfast's new API guide, with the same API key and secret key used for booking. You no longer need to save a Steadfast merchant email and password. The old check logged into Steadfast's website, and it stopped working when that login changed. When Steadfast says other merchants have reported a customer, the order form shows how many reports there are.

### Changed

- **Steadfast settings follow Steadfast's new API guide and Webhook page** (Courier → Providers → Steadfast):
  - The page shows the **Webhook Callback URL** to paste into Steadfast's Webhook page. A **Generate** button fills in the auth token. The token may contain only letters, digits and `- _ . =`, as Steadfast requires.
  - Incoming webhooks are checked two ways: the `Authorization: Bearer` token and Steadfast's new `X-Signature` (HMAC-SHA256 of the body). A retried event is handled once, using its `Idempotency-Key`.
  - Account events (payouts, pickup, return or cancel requests, account changes) are logged as **Ignored**. Before, they were retried five times and then raised a failed-webhook alert.
  - **Statuses ending in `_approval_pending` now stay In Transit.** Steadfast says these are only the rider's report, not confirmed. Before, `delivered_approval_pending` marked the order Delivered right away. An order now moves to Delivered or Cancelled only when Steadfast confirms it. `exception` (lost or damaged) is now Failed. `cancelled_return_received` is now Returned. `unknown` leaves the current status unchanged.
  - The **Balance** button explains a failed call: wrong keys (401), account inactive or KYC needed (403), key paused for too many failures (429), or Steadfast unreachable.
  - The "External Fraud Check (Merchant Panel Login)" section is no longer shown for Steadfast.

**Technical Notes:** `SteadfastCourierClient` gains `ping()` and `fraudCheck()` (`GET /fraud_check/score/{phone}`), URL-encodes path values, and retries only connection errors and 5xx. It never retries a 4xx: the guide says 10 failed logins in 5 minutes lock the key for 60 minutes, booking included. `SteadfastFraudClient` now calls the API instead of scraping the portal (the portal code is removed). After a 401 it skips lookups with the same keys for 15 minutes. The guide does not name the response fields, so `parseScore()` accepts the common spellings, at the top level or under `data`. A response with no delivered or cancelled count is treated as a failed check, never as a clean 0/0. `ExternalCourierFraudService` passes a Steadfast provider's booking API keys and `base_url` to the client. `CourierProviderInterface` gains `verifyWebhookRequest()` and `webhookSkipReason()`, with defaults in `AbstractCourierAdapter`. `CourierWebhookController` uses them and reads `Idempotency-Key`. `ProcessCourierWebhook` marks skipped events `ignored`. `CourierService::normalizeSteadfastStatus()` now returns `?string`. New strings go through `__()` with keys in `lang/en.json` and `lang/bn.json`. No migrations.

## [2.26.0] - 2026-09-27

**Release type:** Minor Feature Update

### Added

- **✨ Enhance button on Video Generation.** It works the same way as on Image Generation: write a rough idea, click **✨ Enhance**, compare your prompt with the rewrite, edit it if you like, then **Use enhanced** or **Keep original**. For video, the rewrite describes camera movement and what moves in the scene, starting from your starting image, instead of describing a new picture. It follows the **What is this for?** choice (Product Demo, Ad Creative, Landing / Offer Hero Video, General) and your brand style. The prompt you started with is saved with the video.
  - The button shows only when a super admin has set up the Prompt Enhancer (**Settings → Integrations → Prompt Enhancer**).
  - **Stability AI makes the video from the starting image only and does not use the prompt.** When a Stability provider is picked, the form says so under the prompt and the Enhance button is hidden, so no AI credit is spent on a prompt that is never sent. The prompt is still saved with the video.
- **Video guides on the Prompt Enhancer page.** Settings → Integrations → Prompt Enhancer → **Context guides** now lists four video guides next to the image ones. Labels now start with "Image —" or "Video —", so the two "General" guides are easy to tell apart. As before, leave a guide blank to keep the built-in default.

**Technical Notes:** `config/prompt_guides.php` gains four `video_generation.*` contexts and a separate `video_provider_styles` map (only `custom`). The image `provider_styles` entry for Stability asks for keyword tags, which suits images but not a video motion prompt. `PromptEnhancementService` picks the medium from the context key prefix (`video_generation.*` → video): the system prompt's first and last lines say video / on-screen text, and `PromptGuideRepository::providerStyle($format, $medium)` reads the matching style map. The `enhance()` signature is unchanged. `VideoGenerationClient` gains `usesTextPrompt()` (`StabilityVideoProvider` returns false). `VideoGeneration` stores `original_prompt` (the column already existed). No migrations. Plan: `12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md` Phase 2.

## [2.25.1] - 2026-09-27

**Release type:** Patch/Fix Update

### Changed

- **Your own INV investor sheets import as they are, with no editing.** The importer now reads the layout you already use:
  - a title line above the column names, like "… (INV 1 - 10/04/2026)"
  - an SL column
  - a total row at the bottom (মোট ইনভেস্টমেন্ট এমাউন্ট)
  - Bangla column names with your own spellings: ইনভেস্টরদের নাম, ফোন নাম্বার, নমিনীর নাম, নমিনী NID/Passport, স্টাম্প নং., চেক নং.
  - Bangla digits in phone, NID, stamp and cheque numbers
- **Every sheet of an Excel file is read.** An Excel file with sheets INV 1, INV 2 and so on imports all of them in one go. Each draft shows which sheet and row it came from.
- **Several numbers in one phone cell.** The Bangladeshi mobile (01…) is used, and the other numbers are shown to the reviewer as a note.
- **Investment amount.** The **বিনিয়োগ (৳)** column is shown on each draft as a note, so you can add the investment after confirming.
- **Extra NID check.** An NID that looks like a phone number is flagged for review.
- **Sample CSV** now downloads in the same layout as your INV sheet.

**Technical Notes:** `InvestorImportService` finds the header row below a title line, reads every Excel sheet, folds Bangla spelling variants when matching column names, and skips SL/total rows. The sample layout and new aliases live in `config/investor_import.php`. No migrations.

## [2.25.0] - 2026-09-27

**Release type:** Minor Feature Update

### Added

- **Video Generation tool (AI Tools → Video Generation).** Turn a product photo and a prompt into a short marketing video. Pick a starting image (upload one, or pick an image marked "video reference" in the Image Library), a provider, the video shape (16:9, 1:1 or 9:16) and a length preset, then click Generate. The video is made in the background, so the page can be closed. The results list updates itself, and a bell notification arrives when a video is ready or has failed. Finished videos are saved to the company's storage and the Media library.
  - A super admin sets up providers on **Settings → Integrations → Video Providers**: **Stability AI** (image-to-video) or a **Custom / self-hosted** endpoint. Each provider has its own encrypted, per-company API key, and an optional approximate cost per video for spend estimates.
  - Stability AI makes one short clip of a fixed length. It ignores the length preset, and the form says so.
  - The built-in Manager role now has the `ai_tools.video_generation` permission. It can be given to any custom role.
  - Not in this release: attaching a video to a product or offer, the prompt enhancer, a video library and usage limits. These come in later phases.

  **Technical Notes:** migrations `2026_09_20_000100_create_generated_videos_table`, `2026_09_20_000200_add_video_path_to_products_and_offers_tables` (nullable `video_path` on `products` and `offers`, used by the later attach phase) and `2026_09_20_000300_reseed_default_user_roles_for_video_generation` (refreshes the built-in role snapshot). Run `php artisan migrate --force`. New company-owned `GeneratedVideo` model (`BelongsToCompany` + `CompanyScope`, added to `MultiCompanyIsolationTest`). Provider layer in `app/Services/VideoGeneration/`: a `submit()` + `poll()` `VideoGenerationClient` contract, `StabilityVideoProvider` (`POST /v2beta/image-to-video`, then `GET .../result/{id}`: 202 = pending, 200 = video bytes) and `CustomVideoProvider`, resolved by `VideoProviderResolver`. Settings are stored at `companies.settings->ai_tools->video_generation` by `VideoProviderSettingsService`. Queued `GenerateVideoJob` submits once, then polls by `release()`-ing itself with back-off (15 s up to 60 s) until a 30-minute `retryUntil()` deadline. It streams the finished file to disk (`Http::sink`) instead of holding it in memory, sets and clears `CompanyContext` on every run, and a `failed()` safety net marks rows that time out. **Production needs a running queue worker.** Plan: `12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md` (Phases 0–1).

### Changed

- **The AI Tools menu now lists only the tools.** Provider and API-key setup moved to **Settings → Integrations**, in a new **AI tool providers** section with a card for each: **Image Providers**, **Video Providers**, **Prompt Enhancer** and **Smart Scan**. Each card opens the same settings page as before; its breadcrumb leads back to Integrations. Old `/admin/ai-tools/...` links to these pages redirect to their new address.
- **Image Governance** is now a button at the top right of the **AI Tools** page (super admins only) instead of a menu item.
- **AI Expense Scan is renamed Smart Scan** everywhere it shows: the settings page, **Finance → Smart Scans**, and its messages.

**Technical Notes:** `ImageProviderSettings`, `VideoProviderSettings`, `PromptEnhancerSettings` and `ExpenseScanSettings` moved from the `AiTools` to the `Settings` cluster and use the new `OpenedFromIntegrations` trait (hidden from navigation, breadcrumb to Integrations). Their URLs are now `/admin/settings/{slug}` (slugs unchanged); a `301` route redirects the old `/admin/ai-tools/{slug}`. `Integrations::aiToolProviderLinks()` builds the cards, showing each only to users who can open that page. `ImageGovernance` stays in `AiTools` with `$shouldRegisterNavigation = false`, linked from the `imageGovernance` header action on `ToolMenu`. New and renamed labels go through `__()` with keys in `lang/en.json` and `lang/bn.json`. No migrations.

## [2.24.0] - 2026-09-27

**Release type:** Minor Feature Update

### Added

- **Import investors from Excel, CSV or pasted text. Each one waits as a draft until you review and confirm it.** Go to Investments → **Investor Drafts**, or use **Import from Excel / CSV / text** on the Investors page.
  - **Import Excel / CSV:** upload an `.xlsx`, `.csv` (or `.ods` / `.txt`) file. Column names can be in English or Bangla (Name / নাম, Phone / মোবাইল, Father's Name / পিতার নাম, NID / এনআইডি, Nominee / নমিনি, and so on). **Sample CSV** downloads a file with every column.
  - **Paste text:** paste investor details as `Label: value` lines, with a blank line between investors, or paste a table copied from Excel.
  - Every investor found becomes an **investor draft**, with the investor form already filled in. Drafts are not investors yet: they do not appear in the investor list, pickers or reports.
  - Open a draft (**Review**) to check and correct it, then **Save & confirm**. You can also **Confirm** from the list, or select several and use **Confirm selected**. Only then is the investor added to the main investor list, and the draft is removed.
  - A draft with a missing name or phone, a phone that already belongs to an investor, a phone that repeats in another draft, or an invalid email shows **Needs fixing** and cannot be confirmed until it is fixed. Notes from the import (a date of birth that could not be read, an unknown channel partner, a line that was not understood, an NID that Excel may have rounded) are shown on the review page.
  - Phone numbers are cleaned automatically (`+880 1712-345678` → `01712345678`), and Bangla digits are read as numbers.
  - **Discard** removes a draft you do not want.

**Technical Notes:**
- New migration `2026_09_26_100000_create_investor_drafts_table` (`investor_drafts`, company-owned; `nid_number` / `nominee_nid_or_passport` encrypted like on `investors`). Run `php artisan migrate --force`.
- New `InvestorDraft` model (`BelongsToCompany` + `CompanyScope`), `App\Services\Investment\InvestorImportService` (reading with the existing `openspout/openspout` dependency), `InvestorDraftResource` in the Investments cluster. Column/label names live in `config/investor_import.php`.
- Drafts need the `investments.manage` permission. Uploaded files are deleted right after they are read.

## [2.23.1] - 2026-09-26

**Release type:** Patch/Fix Update

### Changed

- **The order summary card is now sent as an image.** On the order page, **Order summary card** now shows the card as a picture: company header, customer and order details, an items table, and totals with the due amount highlighted. Each app button sends that picture instead of text:
  - **WhatsApp** opens the customer's own chat with the image attached. If both WhatsApp and WhatsApp Business are installed, you choose which one.
  - **WeChat**, **Messenger** and **Telegram** open that app with the image attached, and you pick the chat.

  **Copy text** still copies the summary as text.

### Fixed

- **Download image now works in the Android app.** The image is saved to the phone's gallery under Pictures/ZamZam. On Android 9 and older, the share menu opens instead, where you can save it.

**Needs a new Android app (APK).** Sending the image and saving it in the app both use new native code. Install the new APK that CI builds from this push (Actions → CI → `business-dashboard-debug-apk`). Until then, the old app shows a message asking you to update it.

On other devices:
- **Phone browser:** each button opens the phone's share menu with the image. Pick the app there.
- **Computer:** the image is downloaded and the app's web version opens, so you can attach the image there.

**Technical Notes:**
- New `android/app/src/main/java/com/zamzamint/erp/ShareBridge.java`, exposed as `window.ZzShareBridge` from `MainActivity`:
  - `shareImage()` writes the PNG to the cache and sends an `ACTION_SEND` intent through the existing FileProvider, targeted by package name. WhatsApp also gets the `jid` extra for the customer's chat.
  - `saveImage()` writes to MediaStore on Android 10+.
  - `AndroidManifest.xml` gains a `<queries>` block for the seven messenger package names, needed for Android 11+ package visibility.
- `OrderSummaryCardService` drops the text share links. It adds `customer_phone` (international digits), per-row `key` on totals, and card `labels`.

## [2.23.0] - 2026-09-25

**Release type:** Minor Feature Update

### Added

- **Order payments now use your Accounts as the payment method, so finance updates automatically.** When a customer pays, you pick the **Payment Method** from your own active money accounts (Accounts page: Cash, bKash, Nagad, bank, and so on). The money is posted into that account straight away, so the account balance, the ledger and the "Customer Payments" figures on the dashboard and reports update by themselves. This applies in three places:
  - the **Paid Amount** on the new-order form. A payment method is required when something was paid.
  - **Payments History** on an order.
  - **Customer Payments**. The old "Receive To Account" and "Method" fields are now one **Payment Method** field.

  Editing a payment's amount or account moves the money. Deleting the payment removes it. A company with no money accounts yet still sees the old generic method list.
- **Courier bookings carry an automatic note: "Order placed from {company name}".** It is filled into the note field of every courier booking: single, bulk, Manual, Steadfast, Pathao (special instruction), RedX (instruction) and E-Courier (comments). The company name comes from Company Settings. Anything staff type in the note is added after it, and the line is never added twice.
- **Call and WhatsApp icons under the customer's phone number in the Orders list.** Tap **Call** to open the phone's dialer with the number filled in. Tap the **WhatsApp** icon to open a WhatsApp chat with that customer directly. Local numbers (01…) get the 880 country code automatically.
- **Order summary card on the order page.** A new **Order summary card** button shows a summary: invoice, date, customer, phone, address, status, courier and tracking, the items, and the totals with paid and due. From there you can send it to:
  - **WhatsApp:** opens the customer's own chat with the summary.
  - **Telegram**
  - **Messenger** and **WeChat:** the summary is copied first, because these apps can't take text from a link, then the app opens so you can paste it.
  - **Share as image:** a PNG of the card, on phones that support sharing.
  - **Download image** or **Copy text**

### Fixed

- **The app now reopens the company you were last working in.** Before, after logging out, closing and reopening the app, or an app update, the dashboard always went back to your default company. It now remembers the last company you switched to, or All Companies for Super Admins, and opens there. If you have since lost access to that company, you get your default company as before.

**Technical Notes:**
- Migration `2026_09_25_120000_add_account_id_to_order_payments_table` adds a nullable `order_payments.account_id` (FK to `accounts`, null on delete). `OrderPayment` now syncs a `transaction_ledgers` row (`type = customer_payment`, `direction = in`, `reference_type = OrderPayment`) on save and delete, and derives `method` from the account type. Payments without an account post nothing, so older rows and storefront/gateway payments are unchanged. `Order::forceDeleting` removes these postings, because the DB cascade skips model events.
- Migration `2026_09_25_120100_add_last_company_selection_to_users_table` adds nullable `users.last_company_selection` (company id or `all`). `CompanySwitchController` writes it. `SetCurrentCompany` uses it only when the session has no company yet, and still checks access.
- New `App\Support\PhoneLinks`, `App\Services\OrderSummaryCardService`, `CourierService::courierNote()`. The courier note text is always English because the courier reads it. It is not UI text.
- New tests: `OrderManagementFeaturesTest`, plus new cases in `CourierIntegrationTest` and `CompanySelectionPersistenceTest`.

## [2.22.0] - 2026-09-25

**Release type:** Minor Feature Update

### Added

- **AI Expense Scan can now read expenses pasted as text, not just photos.** The scan page (Finance → Expense Scans → Scan photo or text, or **Scan with AI** on the Expenses list) has a new **"Or paste expenses as text"** box. Paste several expenses at once, one per line in any format (for example a WhatsApp message or a notes-app list, in Bangla or English). They are read into draft lines exactly like a photo: dates, amounts, suggested category and suggested pay-from account, with the same review and **Publish** step before anything is posted. A scan can have photos, pasted text, or both. The pasted text is shown on the review page and on each published expense as its source.

  **Technical Notes:** migration `2026_09_25_110000_add_source_text_to_expense_scans_table` adds a nullable `expense_scans.source_text` column. `ExpenseScanReader` sends the pasted text to the model as a delimited text block alongside any photos, and a scan needs at least one of the two.

## [2.21.0] - 2026-09-25

**Release type:** Minor Feature Update

### Added

- **VAT on storefront orders (Storefront Settings → Checkout & Delivery).** VAT is **off by default**, so existing totals do not change until you turn it on. Once on, you can set:
  - **Rate:** one store-wide rate, or **Use each product's own VAT rate**, which applies the rate from Products → VAT rate to that product. Products with no rate of their own use the store rate.
  - **Price mode:** *Excluding VAT* adds VAT on top at checkout; *Including VAT* only shows the VAT share already in the price.
  - **Label:** the name shown to customers, e.g. "VAT" or "Mushak".

  VAT is calculated on products only, never on delivery. It appears on the checkout summary, the Offer landing checkout and the product page ("Price includes / excludes VAT"), and is saved on the order's VAT field.
- **Delivery time per area (Storefront Settings → Checkout & Delivery).** You can now enter a delivery time for inside Dhaka and for outside Dhaka (e.g. "1–2 days"). It is shown on product pages. At checkout it updates live for the area detected from the customer's address. It stays hidden when left empty. The area-based delivery charges (first kg + each additional kg, inside/outside Dhaka) already existed and are unchanged.
- **Marketplace Pro: its own header, footer and mobile navigation on every storefront page.**
  - **Top bar:** an optional utility bar (off until you turn it on) with your message, helpline and Track order link.
  - **Header:** navy header with logo, a large search box with an orange button, Account / Returns & Orders / Cart with item count.
  - **Category strip:** a category row under the header.
  - **Category drawer:** slides in from the left.
  - **Mobile:** a 5-item bottom bar (Home · Categories · Search · Cart · Account).
  - **Footer:** navy, built from your existing Footer Builder blocks.

  Other themes are unchanged.
- **Marketplace Pro: new admin-managed homepage content (Storefront Settings → Marketplace Pro Content):**
  - utility bar message and helpline
  - trust strip items (icon, title, subtitle; up to 6)
  - bulk pricing rows
  - business account heading and text
- **Marketplace Pro headline font: Unbounded** (owner's choice, similar to the Nura style). It is used only for the hero heading, section titles, business callouts and the store name/logo mark. Product names, prices, buttons and body text keep your Typography settings so the catalogue stays easy to read. Bangla text uses your heading font. The font is served from our own server (no Google Fonts request) and is downloaded only on Marketplace Pro pages (~51 KB).
- **Marketplace Pro text font: Nunito** (owner's choice). It is a rounded font that pairs with the Unbounded headlines and is used for product names, prices, menus, buttons and body text. It is served from our own server and is ~39 KB. Bangla text uses your Typography font.
- **Marketplace Pro colour preset** from the design handoff (navy #0F2A43, orange #FF6A00). It is applied automatically when you switch a store to Marketplace Pro and can still be edited under Theme Color Palette.
- **Bangla + English groundwork.** The i18n plan is in `docs/i18n-bangla-english-plan.md`, and `lang/en.json` and `lang/bn.json` are added. All new storefront text in this release is translatable. Bangla is not switched on yet; the plan covers how, including a per-country default language.

### Changed

- **Marketplace Pro homepage hero is now your hero banner only** (Storefront → Hero Slides). The promo cards and the built-in sample statistics ("Up to 30%", "24–48h dispatch", "products ready") are removed. When no hero slide exists, the campaign heading you entered is shown instead; if that is also empty, no hero is shown.
- **Marketplace Pro no longer shows built-in default copy.** Campaign headings, trust strip subtitles, bulk pricing rows and business account text now come only from your settings. Anything left empty is hidden. Stores that already set the shared Trust Strip messages keep them as trust strip titles.

### Fixed

- **Preview URLs (`/storefront/{slug}/…`) now tell search engines not to index them,** so they can no longer compete with the live store domain in search results.

**Technical Notes:**
- Migration `2026_09_25_100000_add_vat_and_delivery_time_to_storefront_settings_table` adds `vat_enabled`, `vat_rate`, `vat_mode`, `vat_label`, `vat_use_product_rate`, `delivery_time_inside` and `delivery_time_outside`.
- Migration `2026_09_25_100100_add_marketplace_pro_content_fields_to_storefront_settings_table` adds `marketplace_trust_items` (json), `marketplace_bulk_pricing_rows` (json), `marketplace_business_heading`, `marketplace_business_text`, `marketplace_helpline`, `marketplace_utility_bar_enabled` (default off) and `marketplace_utility_bar_text`. All columns are nullable or have defaults. The legacy `marketplace_announcement_*` columns default to enabled and stay unused, so an old hidden announcement never reappears.
- VAT is computed by `StorefrontSetting::vatAmountForItems()` and flows through `CheckoutController` into both paths: the direct order and `checkout_data` for gateway-verified orders (`StorefrontOrderPlacementService`). `OfferCheckoutController` uses the same method. The existing `OrderWorkflowService` total formula already adds `orders.vat`.
- New `StorefrontThemeRegistry::view()` / `page()` resolve `storefront.themes.{directory}.{page}` and fall back to `storefront.{page}`. All 30 storefront controller `view('storefront.…')` calls now go through it.
- The default header, footer and mobile nav moved unchanged into `storefront/partials/layout/*`, which lets a theme override them.
- Unbounded and Nunito font files (SIL Open Font License 1.1; licences kept at `resources/fonts/{unbounded,nunito}/OFL.txt`) are latin and latin-ext WOFF2 subsets in `resources/fonts/`, bundled by Vite through `@font-face` in `resources/css/app.css` and applied only under `body[data-storefront-theme='marketplace_pro']`.
- `axios` was removed. It was unused (`resources/js/bootstrap.js` deleted), which drops ~14 KB gzip from the storefront bundle.
- New tests: `StorefrontVatAndDeliveryTimeTest`, `tests/Unit/TranslationParityTest`. `StorefrontThemeTest` was updated for the owner's new hero, copy and utility-bar rules.
- New agent rule in `CLAUDE.md` / `AGENTS.md`: every new user-visible string goes into both `lang/en.json` and `lang/bn.json`.

## [2.20.0] - 2026-09-25

**Release type:** Minor Feature Update

### Added

- **AI Expense Scan (Finance → Expense Scans): photograph an expense note and let AI enter the expenses for you.** Take or upload a photo of any expense list (a handwritten notebook page, a receipt, a whiteboard, a screenshot), in Bangla, English, or both. An AI vision model reads every line into a draft: date, description, amount, a suggested category, and a suggested pay-from account. **Nothing is published automatically.** A review page shows the photo beside the draft lines. Correct anything the AI misread, add or remove lines, then press **Publish** to turn them into real expenses. Also reachable from a new **Scan with AI** button on the Expenses list.
  - **Dates:** a date written as a heading applies to the lines under it. A line with no date on the note is left **blank** for you to fill in and cannot be published until you do.
  - **Categories:** the AI picks one of your existing expense categories. When none fits, it suggests a new category name, which is only created when you publish.
  - **Pay-from account:** optionally pick a default account when uploading. If the note says how a line was paid (e.g. bKash, Cash) and that matches one of your accounts, the AI suggests that account instead. Every line's account can be changed during review.
  - **Warnings on the review page:** a line the AI was unsure about, and a line with the same date and amount as an expense already recorded (possible duplicate), are both flagged.
  - **Publishing is all-or-nothing:** if any line is incomplete, or would take an account below zero, nothing is posted and you are told which line to fix.
  - **Proof kept:** each published expense's page shows the original photo it was read from.
  - **Who can use it:** only users whose role has the new **"Expenses: AI Scan (upload photo, review and publish)"** permission (Super Admins always can). Grant it to a custom role under Settings → User Roles.
  - **Setup:** a Super Admin sets the AI model and API key on the new **AI Tools → Expense Scan** page. Any Anthropic (Claude) or OpenAI-compatible vision model works. This key is separate from the CRM and Prompt Enhancer keys, so its cost can be tracked on its own.

  **Technical Notes:** migration `2026_09_24_100000_create_expense_scans_tables` adds `expense_scans` and `expense_scan_items` (both company-owned: `BelongsToCompany` + `CompanyScope`, added to `MultiCompanyIsolationTest`) and a nullable `expenses.expense_scan_id` FK (`nullOnDelete`). Drafts are deliberately kept out of `expenses`, because an `Expense` posts to the ledger in its `saved` hook. Photos are stored privately through `CompanyStorageService` (`private/expense-scans/`) and served by the new permission-checked `expense-scans.image` route. The vision call runs in the new queued `ProcessExpenseScanJob` (`tries=1`, `timeout=240`). It sets `CompanyContext` explicitly and restores it afterwards, so it also works under `QUEUE_CONNECTION=sync`. **Production needs a running queue worker for scans to be read off the request.** New services `ExpenseScanConfigService` (encrypted per-company settings at `settings->ai_tools->expense_scan`), `ExpenseScanReader` (vision prompt + `record_expenses` tool; re-validates every category/account id, amount and date the model returns), and `ExpenseScanPublisher` (transactional publish, category de-duplication, duplicate hint). `AiLlmClient` gained an optional `$maxTokens` constructor argument (default unchanged at 1024). `ExpenseCategory::createWithUniqueSlug()` now holds the unique-slug logic previously inlined in `ExpenseForm`. New permission key `expenses.ai_scan` is not added to any built-in role.

## [2.19.0] - 2026-09-20

**Release type:** Minor Feature Update

### Added

- **New external Website Integration API** (Settings → Integrations → Website API), so a company's own customer-facing website can sync with ZamZam ERP: two-way product price/stock sync, website → ERP order and inquiry submission, and one-way ERP → website order status tracking. Real-time in both directions — inbound via a REST API, outbound via signed webhooks. Generate a per-company API key and (optionally) a webhook URL/secret from the new tab; full contract, auth, and webhook signature verification are documented in `docs/api/website-integration.md` and `docs/api/website-integration.openapi.yaml` (importable straight into Postman), with a staging setup walkthrough in `docs/api/staging-setup.md`.

  **Technical Notes:** new `routes/api.php` (registered in `bootstrap/app.php`), authenticated by a new per-company `CompanyApiKey` model (SHA-256-hashed bearer token, looked up before company context exists — same `withoutGlobalScopes()` precedent as `ChatOrderLink`) via the new `ResolveCompanyFromApiKey` middleware, which sets `CompanyContext` for the request. New endpoints under `/api/v1`: `GET/PATCH products/{sku}`, `GET products`, `POST orders` (idempotent on `external_reference`, new `Order::SOURCE_API`), `GET orders/{order_number}`, `POST leads` (reuses the existing `Lead::SOURCES['website']`, no model change needed). A `PATCH products/{sku}` stock update is never a raw column overwrite — `Product::setStockFromProductForm()` (already used by the admin Product form) now accepts an optional reference/reason override so the resulting `StockMovement` is stamped as API-originated, ledger-safe like every other stock change. Outbound webhooks (`order.status_updated`, `product.updated`) go through a new `WebsiteWebhookDispatcher` service + queued `DispatchWebsiteWebhookJob` (HMAC-SHA256-signed, `X-ZamZam-Signature` header), configured per company on a new encrypted `storefront_settings.website_api_credentials` column (same pattern as `woocommerce_credentials`); an API-originated stock change is deliberately not echoed back as a webhook to the same company that made it (checked via the `StockMovement.reference_type` it was stamped with), while every other stock/price/order-status change still notifies normally. New `CompanyApiKey` is added to `MultiCompanyIsolationTest`'s company-scope contract test.

## [2.18.2] - 2026-09-17

**Release type:** Patch/Fix Update

### Fixed

- **Orders: auto shipping fee could stay ৳0 even with a correctly detected zone, when a company has more than one courier provider.** `ShippingFeeService` priced a detected zone using whichever active courier provider happened to have the lowest database id — not necessarily the one actually marked "Set as default courier." A company with a second courier added later (or fees only configured on the real default) would silently get ৳0 shipping on every order regardless of the customer's address, and the CRM sales bot's delivery-fee quote had the same problem.

  **Technical Notes:** `ShippingFeeService::defaultCourierProvider()` now delegates to `CourierProvider::defaultForCompany()` — the same "is_default, falling back to the sole active provider" lookup already used to pre-assign `courier_provider_id` on new orders — instead of its own inconsistent `orderBy('id')->first()` query.

## [2.18.1] - 2026-09-17

**Release type:** Patch/Fix Update

### Fixed

- **Orders: auto shipping-cost zone detection now reads a customer's address in Bangla too, and a manually typed shipping fee is no longer wiped out when auto-detection fails.** Previously the zone matcher only recognized a district's official English spelling (e.g. "Chattogram"), so an address written in Bangla script (ঢাকা, চট্টগ্রাম, …) or an older/informal English spelling (Chittagong, Comilla, Bogra, Barisal, Jessore, …) always fell through to "Could not detect a zone from the customer address" — and on top of that, if staff then typed the shipping fee in by hand, saving the order silently reset it back to ৳0.

  **Technical Notes:** `ShippingFeeService::determineZone()` now also matches each configured zone keyword against `BangladeshDistricts::aliasesFor()` (the Bengali name and any older English spelling for that district/suburb). `Order`'s `creating` hook now only auto-computes `shipping_fee` when one hasn't already been set to a real amount, so a manual entry survives even when `shipping_zone` stays null.

## [2.18.0] - 2026-09-15

**Release type:** Minor Feature Update

### Added

- **CRM Auto Messaging can now use a separate model just for reading customer photos, and a separate model to transcribe voice notes.** Previously, "Read customer images (vision)" required the main chat model itself to be vision-capable, and a voice note got no AI reply at all. Two new options on the Integrations page (Auto Messaging tab):
  - **"Use a separate model for images"** — turn this on to pick its own provider/model/API key (any Anthropic or OpenAI-compatible vision model) just for reading photos. That model describes the photo in plain text first; only the description (never the photo itself) reaches the main chat model — useful when the main model has no vision support, or a cheaper/better model should handle photos specifically.
  - **"Transcribe voice messages"** — turn this on and add a Whisper-compatible transcription provider (OpenAI, Groq, or a self-hosted Whisper server) to have voice notes transcribed and answered exactly like a typed message (FAQ matching, handoff keywords, and the sales agent all apply to the transcript). Left off, a voice note now gets a graceful "please type it" reply instead of silently going unanswered.
  - Both are optional and off by default — existing configurations (main model handles images inline, voice notes unanswered) are unchanged unless explicitly turned on.

  **Technical Notes:** new `AiVisionDescriber` and `AiVoiceTranscriber` services; new encrypted `image_api_key`/`voice_api_key` settings alongside the existing per-tool credential shape in `AiSettingsService`. `DownloadConversationMediaJob` now also queues the AI reply job for incoming voice notes (previously only images), and `AiReplyService` writes the transcript/description onto the source message's own `body` so it flows through the existing text-based reply pipeline unchanged.

## [2.17.0] - 2026-09-13

**Release type:** Minor Feature Update

### Added

- **Investor / Mudarabah module, Sprint 3 (P3 — polish, PII, test coverage):** NID/passport numbers are now encrypted at rest, the settlement view shows gross profit, an investment's cheque number is visible without an extra click, and three genuine bugs from the audit are fixed.
  - **PII encryption:** an investor's NID/passport, their nominee's NID/passport, and a security instrument's guarantor NID/passport are now encrypted at rest (the same `encrypted` Eloquent cast this app already uses for stored access tokens and storefront checkout details) instead of sitting in plaintext columns.
  - **Gross profit** (Selling − Landed Cost) now shows on a settlement's own admin page, not only on the printable per-investor report.
  - **Inline cheque number:** a project's Investments table now shows each investment's security-cheque number directly, without opening its separate Security tab.
  - **Bug fix — three relation managers were silently unusable:** creating a Security Instrument or a deed Witness (on an investment's page), uploading a document against a settlement, and correcting a payout's recipient bank details before marking it paid **could never actually be done from the admin panel** — those pages have no separate "Edit" page, and Filament's own default quietly freezes every relation manager on a view-only page. All four are fixed; nothing about who's allowed to do them changed.
  - **New test coverage:** the settle action's on-screen form, both "Mark as Paid" flows (investor and channel partner), a user without settlement permission correctly seeing those actions hidden, and creating a Security Instrument with an uploaded contract — all now covered end-to-end, alongside the Investments navigation cluster.

  **Technical Notes:** migration `2026_09_13_100000` converts `investors.nid_number`/`nominee_nid_or_passport` and `investor_security_instruments.guarantor_nid` from `string` to `text` (an encrypted payload runs longer than 255 chars) and re-encrypts existing plaintext values in place (idempotent — a second run is a no-op via a decrypt-first probe); reversible. `nid_number` dropped from the two places it was searched at the database level (`InvestorResource` table column, `InvestmentsRelationManager`'s investor select) since an encrypted column can't be matched with `LIKE`. `SecurityInstrumentsRelationManager`, `WitnessesRelationManager`, `DocumentsRelationManager`, and `PayoutsRelationManager` each gained an `isReadOnly(): false` override — real authorization is unchanged, enforced by the existing `investments` Gate and (for payouts) `payoutsUnlocked()`.

## [2.16.0] - 2026-09-13

**Release type:** Minor Feature Update

### Added

- CRM vision input for privately stored customer images, with a dashboard toggle and compatible-model requirement.

### Fixed

- Messenger subscription failures now retain actionable diagnostics; profile lookup failures are visible in Chat Channels, retry after five minutes on new requests, and retry immediately after a token change instead of remaining blocked for an hour.

- Sync new Messenger Page/Business Suite replies, preserve own-send attribution, deduplicate echoes and pause AI after external replies. Add Messenger Test & Subscribe. Hide IDs in chat titles and directly clarify generic Bengali order requests.

### Fixed

- Release Notes now keeps inline **Technical Notes:** entries in the technical audience even when they contain none of the existing database/deployment keywords. Ordinary feature notes remain visible to all permitted users.

- **The Courier Fraud Check could report a clean "0 delivered / 0 cancelled" Steadfast history for a phone number that actually has real delivery history.** Steadfast's merchant portal redirects on both a correct and a wrong password, so a stale or incorrect Steadfast fraud-check login was read as "logged in"; the following lookup then landed back on Steadfast's login page (HTML, not the expected JSON), and the missing `total_delivered` / `total_cancelled` fields were silently read as `0` instead of being treated as a failed check. The check now requires those fields to actually be present and drops the result (instead of showing a false clean history) when they're not — the same "courier didn't answer" outcome already shown for missing credentials or a network failure. **This does not by itself fix a Steadfast login that's actually wrong — if `SteadfastFraudClient` still can't authenticate, the courier now correctly disappears from the result instead of showing a false 0/0.**

- **Messenger conversations in the CRM Inbox now pick up the contact's real name instead of staying on the generic "Contact 12345…" placeholder.** When a Messenger conversation still has no real name, a background lookup fetches the contact's first/last name from Meta's Graph API and updates the conversation (and its auto-created lead) once it arrives — checked when a new message comes in and again when staff open the conversation. A name a staff member has already typed in is never overwritten. **Meta restricts this lookup for some apps/permission setups — if Facebook returns no name (or an error), the conversation is left as-is rather than showing a false name; this is a best-effort enrichment, not a guarantee.**

### Added

- **Editable sales-agent conversation guidelines:** Settings → Integrations → AI Integration → Auto Messaging now has a native Filament Markdown editor with formatting and a restore-default action. The supplied Bengali sales playbook loads by default; saved company-specific guidance reaches subsequent AI requests without a deployment. Illustrative prices and promises never replace verified product facts.
  - Multi-step AI requests release unused token reservations when actual provider usage arrives; absent usage retains the conservative bound. Unicode guidance is measured as UTF-8 content rather than JSON escape sequences.
- **CRM sales automation (2026-09-10):** evidence-backed lead qualification, staff temperature overrides, variant-aware checkout, draft quotations, AI review/run reports and budget controls, and opt-out-aware scheduled WhatsApp template follow-ups (off by default).
- **Bangla/Banglish sales replies:** understand colloquial Bengali and Latin-letter Bengali; short language-matched replies, requested price/stock details only, no automatic introduction or appended sales questions. Preserve verified catalog facts and disclose AI identity when asked.
- **CRM automation reliability:** persistent human handoff, renewed Inbox presence, conversation-level AI locks, source-message deduplication, internal-note exclusion, send-time suppression, atomic broadcast claims and transaction-protected conversions.

- **Image Generation: "Start from an image" — run Regenerate or Remove background on a photo you upload.** The Image Generation form now takes an optional reference-image upload. With one attached, **Generate** offers *Reimagine it from my prompt* (image-to-image, with a subtle / balanced / strong strength) or *Just remove its background*, and runs that on the upload instead of generating from scratch — the same two operations that were already available as per-image buttons on finished results. The section is hidden unless a configured provider supports an edit operation (Google Imagen is generate-only). The upload is compressed to WebP, stored in the company's storage and registered in the Media Hub, and the derived image goes through the same monthly-cap, approval, and audit-log path as any other generation.

  **Technical Notes:** no schema change — reuses the `generated_images.operation` / `reference_image_path` columns from v2.15.0. New form fields `reference_image` (FileUpload via the shared `OptimizesUploadedImages` path into `CompanyStorageService` `ai-reference-uploads/`), `reference_operation`, `reference_strength`; `ImageGeneration::generateFromUploadedReference()` and a shared `queueDerivedGeneration()` now behind both the gallery actions and the form upload.

- **Investor form now captures the deed-paper and register fields.** Investor Identity gains **Display / Pseudonym** (deed clause 3 — used on shared reports when set, otherwise the real name) and **Date of Birth**, and the NID field is relabelled **NID / Passport**. A new **Nominee** section (deed clause 10) records the nominee's name, NID / passport, phone, relation, and address. A new **Security Documents** section records the **Stamp No.** (৳100 deed-paper stamp serials — comma-separate if more than one) and **Cheque No.** (the undated security cheque). All optional; the same fields show on the investor's view page.

  **Technical Notes:** `2026_09_10_120000_add_nominee_and_security_fields_to_investors_table` adds `display_name`, `date_of_birth`, `nominee_name`, `nominee_nid_or_passport`, `nominee_phone`, `nominee_relation`, `nominee_address`, `stamp_number`, `cheque_number` to `investors`, each guarded with `Schema::hasColumn` so a database that already carries some of them stays a safe no-op. Per-investment stamp serials and the security cheque continue to live on each Investment's Security Instrument record; these investor-level fields are the quick register reference.

### Fixed

- **Opening the CRM cluster (`/admin/crm`) landed on the new Sales Automation page instead of Leads.** Every other CRM cluster item (Leads, Quotations, Inbox, Conversation Channels, Broadcasts, Company FAQs, Quick Replies) declares an explicit `navigationSort`, with Leads at `0` as the cluster's intended landing page; the new `SalesAutomation` and `SalesFollowUps` pages shipped without one, so Filament's default ordering put `SalesAutomation` ahead of Leads and silently changed the cluster root. Both now declare `navigationSort` (`7` and `8`), after every existing cluster item; Leads is the landing page again.

- **Investor / Mudarabah module, Sprint 1 (2026-09-07 audit, `01_INVESTOR_MODULE_v3_GAP_ANALYSIS.md`, P1.1–P1.7):** an owner audit against the real signed deed, channel-partner agreement, and profit-settlement sheets found the calculation core sound but the surrounding workflow thin. This sprint closes it:
  - **Contract & legal fields** — per-investment contract date/reference, stamp serial numbers, security-cheque bank/branch/account/holder, guarantor relation/address, and a "terms explained" acknowledgement on the Security Instrument; deed witnesses on each Investment.
  - **Company gap-fill contribution** — a project's own `company_contribution_amount` (with a note) closes an investor shortfall against the target raise; that capital shares the investor-pool rate on top of the company's own 50%, spread over `external investor capital + company contribution` — channel-partner proration still runs on external capital only.
  - **Document attachments** — a private, company-scoped `investment_documents` table (settlement sheets, investor reports, cost receipts, bank slips, signed contracts) attaches to cost items, investments, projects, and settlements, with authenticated download. A direct cost item with no linked Purchase and no attached receipt now blocks settlement unless explicitly acknowledged.
  - **Printable reports** — a per-investor payout report and a project investor register, both browser-print Bengali-correct HTML (not dompdf, which cannot shape Bengali conjuncts), pulling the company's logo/address/date format.
  - **Corrected annualized return** — now the investor pool's yield on capital (not net profit's), annualized on a 360-day year to match the signed settlement sheet.
  - **Settlement draft → confirm → void** — a settlement starts as an editable-nothing `draft` (figures computed, no payout unlocked), is promoted to `confirmed` by a Super Admin (locks the figures, unlocks "Mark as Paid"), or can be `voided` — deleting the settlement and its payout schedule and re-opening the project — as long as no payout has been paid. Status only moves forward.
  - **Loss handling** — a losing settlement (net profit < 0) requires an explicit `outcome` and a reason: `loss_investor_borne` erodes each investor's capital in proportion to their share (deed clause 4, force-majeure loss), or `loss_manager_borne` returns full principal to every investor and the company absorbs the whole loss (its own negligence/breach).

  **Technical Notes:** 4 migrations — `2026_09_07_100000` (contract/nominee/capital fields across `investors`, `investor_security_instruments`, `investment_projects`, `project_settlements`), `2026_09_07_100100` (`investment_witnesses`), `2026_09_07_100200` (`investment_documents`, polymorphic, `BelongsToCompany`), `2026_09_07_100300` (`project_settlements.status` enum → string to admit `draft`; new `outcome`/`loss_reason` columns) — all idempotent (`Schema::hasColumn`/`hasTable` guarded). New models `InvestmentWitness`, `InvestmentDocument` (both `BelongsToCompany` + `AuditObserver`, registered in `MultiCompanyIsolationTest`). `SettlementService::calculateAndSettle()` takes `outcome`/`lossReason`/`acknowledgeUnprovenCosts`; new `confirmSettlement()` / `voidSettlement()`. Three new routes under `/admin/investments/...` for document download and the two reports.

- **Investor / Mudarabah module, Sprint 2 (P2.1–P2.5):** channel-partner modeling, three code-audit bug fixes, an optional investment-collection window, per-cycle withdraw/reinvest elections with a 60-day exit-notice tracker, and ledger integration.
  - **Channel partner modeling** — an investor can now be flagged **"This investor is also a Channel Partner"**; the Investors list gets a Partner icon column and a filter, the partner's profile gets a signed-agreement document tab and a read-only channel-partner earnings history tab, and deleting an investor is now also blocked while they have anyone referred under them (not just while they hold investments).
  - **Bug fixes from the code audit:** (1) assigning a channel partner to an investor for the *first* time no longer wrongly demands the Super-Admin-only reassignment permission and reason — only an actual *change* of an already-assigned partner does; (2) the delete permission Gate for the investments module was silently hard-coded to deny for everyone but a super_admin, making a Resource's own delete guard pointless — it now defers to `investments.manage`; (3) a project's recorded investments are frozen from the moment its investment window closes (`closed`/`settled`), not only once fully `settled`.
  - **Investment window (optional, per project):** a project can declare an "Investment Window Opens/Closes" date pair; a badge on the project's list and view shows "Closes in N days" / "Window closed". Adding a *new* investment after the window closes (or once the project isn't `open` anymore) now needs a separately-grantable `investments.override_investment_window` permission plus a logged reason — a project that never sets a window is completely unaffected (this is opt-in, not a new mandatory rule on existing projects).
  - **Per-cycle withdraw / reinvest elections (deed clauses 5–6):** on a settlement payout, record whether the investor withdraws it, fully reinvests it, or partially reinvests it into a chosen next project — a (partial) reinvest immediately creates the rollover investment there. Each payout can only carry one election, and it's undoable while the payout is still unpaid.
  - **60-day written exit-notice tracking (deed clauses 5–6):** a new tab on the investor's profile records a notice-given date (effective date auto-computed 60 days out), an optional project, and a reason; mark it honored or cancel it. Tracking only — nothing else in the system is blocked by a pending notice.
  - **Ledger integration (Q5):** a project can optionally name a receiving and a payout Fund Source (builds on the existing Voucher/Fund Source system — no new ledger engine, no separate liability account). With one configured, a new investment books a **pending** `capital_investment` credit voucher, and marking an investor or channel-partner payout paid books a **pending** `investor_payout` debit voucher — someone still verifies/approves it through the normal Voucher workflow. A project that never configures a fund source creates no vouchers, and a voucher-booking failure never blocks the investment/payout itself.

  **Technical Notes:** migration `2026_09_12_100000` adds `investment_projects.investment_opens_at`/`investment_closes_at` (nullable dates) and `investments.override_reason` (nullable text); `2026_09_12_100100` creates `investor_cycle_elections` and `investment_withdrawal_notices`; `2026_09_12_100200` converts `vouchers.transaction_type` from a native enum to a string (same fix as `2026_07_17` / this module's own `2026_09_07_100300`, needed to admit the new `investor_payout` value) and adds the two fund-source columns on `investment_projects` plus a `voucher_id` column on `investments`/`settlement_payouts`/`channel_partner_payouts` — all idempotent. New models `InvestorCycleElection`, `InvestmentWithdrawalNotice`; new services `CycleElectionService`, `InvestmentLedgerService` (wraps the existing `VoucherService::submit()`, never bypasses its validation). `Investor::referredInvestors()`/`channelPartnerPayouts()`/`documents()`/`withdrawalNotices()` relations; `InvestmentProject::isWithinInvestmentWindow()`/`investmentWindowLabel()`. The scheduled per-company "auto-flip status open → running when the window ends" the plan sketched is left undone — it was marked optional there and isn't built.

### Changed

- **Invoice numbers now use one continuous running number per company instead of restarting at `01` every day.** In `PREFIX-YYYYMMDD-NN`, the `NN` was the count of *that day's* orders, so every date produced its own `-01`, `-02`, … and the same suffix repeated across dozens of dates — a specific invoice was hard to locate and the scheme made no sense to a newcomer. `NN` is now the company's total order count so far, plus one, and never resets: two orders placed today read `…-01` and `…-02`, tomorrow's next order is `…-03`, and so on. The date segment stays, for readability only. The number is padded to a minimum of two digits and grows past `99` on its own. **Already-issued invoice numbers are left exactly as they are** — only orders created from the deploy onward use the running number, so the first new one continues from wherever the business currently stands (128 existing orders → next is `…-129`). Trashed orders keep their number reserved. Quotations and storefront complaints keep their own separate daily numbering. `Order::nextOrderNumber()`.
- **The storefront home page and product/category listing pages are now cached per company (10 minutes)**, so a high-traffic storefront doesn't re-run the same product/category query for every single visitor. A plain category/sort/page browse is cached; a search or a reseller's filtered catalog always hits the database instead (per-request/per-customer, not worth caching). Any admin edit to a product, category, or storefront setting invalidates the cache immediately — the 10-minute window is only a safety net beyond that.

### Technical Notes

- New `App\Support\StorefrontListingCache` (`storefront-home-products:`, `storefront-categories:`, `storefront-products:` cache keys). The product-listing page is parameterized by category/sort/page, so instead of enumerating every combination to forget, its keys carry a per-company "generation" number that `forgetCompany()` bumps — old keys simply become unreachable and expire via TTL, on any cache driver. `Product`, `Category`, and `StorefrontSetting` now call it from their existing `saved`/`deleted` hooks (`Category`/`StorefrontSetting` already forgot the separate, pre-existing `storefront-home:{companyId}` slide cache there; that call is unchanged).
- Production Redis is now also the recommended session backend: `SESSION_DRIVER=redis` with `SESSION_CONNECTION=session` (new `redis.session` connection in `config/database.php`, `REDIS_SESSION_DB` defaults to its own database `2`, separate from the queue's `0` and cache's `1`) so a busy storefront isn't reading/writing a session row in MySQL on every request, and a `cache:clear` can never sign out every active session along with it. `.env.production.example`/`.env.example` and `docs/deployment.md` ("Redis on Coolify") updated accordingly; `database` remains supported for both if Redis isn't available yet.
- Full suite: 1213 passed (as of the Sales Automation navigation-sort fix above).

## [2.15.0] - 2026-09-10

**Release type:** Minor Feature Update

### Added

- **Image Generation: "Regenerate" and "Remove background" on any finished image.**
  - **Regenerate** feeds a generated image back in as the starting point for a new one, guided by a prompt you can edit and a "how much to change" setting (subtle / balanced / strong). Uses OpenAI's or a custom endpoint's image-edit API, or Stability's SD3 image-to-image.
  - **Remove background** produces a new copy with the background made transparent (Stability's remove-background). The original is untouched.
  - Both are per-image buttons in the results gallery, and each is only offered when a configured provider actually supports it (Google Imagen is generate-only). Background removal always yields a single image; the derived image goes through the same Media Hub registration, monthly cap, approval, and audit-log path as a fresh generation. The Image Library gains a "Kind" column and filter (Generated / Image-to-image / Background removed).

### Fixed

- **The Orders list's bulk "Change status" action did nothing when the selection contained any order that couldn't legally reach the chosen stage.** `OrdersTable` was missing the `use Illuminate\Validation\ValidationException` import, so the bulk loop's `catch (ValidationException)` matched a non-existent class — the first ineligible order's `ValidationException` escaped the loop, Livewire turned it into a silent component error, and no order was updated and no notification appeared. The bulk **"Book courier"** action had the same latent failure. Restoring the import makes ineligible orders count as "skipped" as intended; a new regression test drives the bulk action with an ineligible order first.

### Technical Notes

- `generated_images.operation` column; `ImageGenerationRequest` carries `operation` + `referenceImage` + `strength`; each provider adapter declares `supportsOperation()` and `ImageProviderResolver::formatsSupporting()` reports which `api_format`s can run a given operation; `GenerateImageJob` loads the reference bytes from `reference_image_path` and rejects an unsupported operation before any HTTP call.

## [2.14.1] - 2026-09-10

**Release type:** Patch/Fix Update

### Changed

- **Hero Slides gained a "Pagination display" control to hide the banner's dot navigation, separately for desktop and mobile.** A new button on the Storefront → Hero Slides list opens a two-toggle form ("Show pagination on desktop" / "Show pagination on mobile"); the dots (and the pause/play button beside them) can be hidden on one display type while staying on the other. Both default on, so nothing changes until an owner turns a toggle off. "Mobile" is every width below the banner's own `1024px` desktop breakpoint. The whole banner carousel is affected, not a single slide. New `storefront_settings.banner_pagination_desktop` / `banner_pagination_mobile` columns; the storefront applies them as `.storefront-image-banner-hide-nav-desktop` / `-mobile` classes on the banner with CSS that hides `.storefront-image-banner-nav` at the matching breakpoint. Autoplay is unchanged.

## [2.14.0] - 2026-09-10

**Release type:** Minor Feature Update

### Added

- **New "AI Tools" area — an Image Generation tool with a shared Prompt Enhancer, a generation library, and governance controls.** A top-level **AI Tools** section in the admin sidebar, separate from the per-module AI features (Ad Assistant, Landing Page Builder, Auto Messaging). It opens a **Tool Menu** hub; Video Generation and Content Creation show as reserved "Coming soon" tiles.
  - **Image Generation** turns a prompt into product shots, ad creatives, or offer banners. Providers are configured on **AI Tools → Image Providers** (super-admin): one or more profiles — OpenAI Images, Google Imagen, Stability AI, or any OpenAI-images-compatible endpoint — each with its own model and **encrypted-per-company** API key; staff pick one at generation time. The tool takes a prompt, a context, an aspect-ratio preset, and 1–4 variations; generation runs in a queued job, results appear in a self-refreshing gallery, and each finished image is compressed to WebP, stored in the company's storage, **and registered in the Media Hub** so it is pickable from every "Select From Media" field.
  - **✨ Enhance** rewrites a rough prompt before generation — adapting to the chosen image provider (natural language for OpenAI/Imagen, keyword tags for Stability) and the company's own visual house-style note. Always explicit: a before/after you edit and accept, or keep your original. Configured on **AI Tools → Prompt Enhancer** (super-admin), where the expert default guidance per context can also be overridden.
  - **Attach actions** — a finished image can be set as a product's featured image (also what Meta ad creatives use) or added to its gallery, set as an offer's landing-page cover banner, or flagged as a reference for the future AI Video tool.
  - **Image Library** collects every generation with filters (context, status, creator, favourites, video references, attached/not, review state), plus "Reuse prompt" (re-opens the tool prefilled) and favourites.
  - **Governance** (**AI Tools → Image Governance**, super-admin, all optional and off by default): a monthly image cap per role, an approval step for chosen roles (their images can't be attached to a record until a reviewer approves them), and a this-month usage / estimated-cost dashboard by person and provider (estimate uses an admin-set "approx. cost per image" per provider — no billing API is called). Every generation, attach, and review decision is written to the Audit Logs.
  - **Access:** new permission keys `ai_tools.menu`, `ai_tools.image_generation`, `ai_tools.image_generation.review`, plus reserved `ai_tools.video_generation` / `ai_tools.content_creation`. Super Admin and the built-in Manager role hold the first three by default; all are selectable on custom roles.

  **Technical Notes:** new `generated_images` table (`BelongsToCompany` + `CompanyScope`, registered in `MultiCompanyIsolationTest`). `companies.settings` gains `ai_tools.image_generation` (provider list), `ai_tools.prompt_enhancer`, `ai_tools.image_governance`, `prompt_guides`, and `image_brand_style` — each owned by its own service (`ImageProviderSettingsService`, `PromptEnhancerConfigService`, `ImageGovernanceService`, `PromptGuideRepository`); `AiSettingsService` is not involved. Provider adapters and every LLM call are `Http::fake()`-mocked in tests.

## [2.13.4] - 2026-09-08

**Release type:** Patch/Fix Update

### Changed

- **Hero Slides gained a per-slide "Fit to frame" toggle.** When on, that banner image is always shown in full — never cropped — with a thin strip of the banner background above/below if it isn't the exact banner ratio (the same "fit to screen" behaviour mobile already uses, now available on desktop for a specific slide). Off by default, so the standard edge-to-edge crop-to-fit is unchanged. New `storefront_slides.fit_to_frame` column; the storefront applies it as a `.storefront-image-banner-fit` class that forces `object-fit: contain` at every breakpoint.

## [2.13.3] - 2026-09-08

**Release type:** Patch/Fix Update

### Changed

- **The Marketplace Pro storefront's hero banner is shorter on desktop**, so the "Shop by category" row directly below it stays in view without scrolling on large screens. Its slot ratio changes from `3:1` to `4:1` (`1920×480`, capped at `30rem` / 480px tall on monitors ≥1920px wide), and the Hero Slides upload editor now locks new artwork to that same ratio. Mobile is unchanged, and the Built-in and Noor Solar themes keep their existing banner sizes. `StorefrontThemeRegistry::BANNER_SPECS`, `.storefront-image-banner` (scoped `body[data-storefront-theme='marketplace_pro']`).

## [2.13.2] - 2026-09-08

**Release type:** Patch/Fix Update

### Fixed

- **An image field whose preview is stuck on "Loading / Waiting for size" can now always be cleared.** When the preview of an already-saved image can't load — a slow or failing company-media URL, an offline mobile webview, the dev server under load — FilePond's own remove ("×") button never appears, so a required image field looked permanently occupied and the whole form couldn't be saved. Every FileUpload in the app now carries a small **"Remove"** action in its label row (next to "Select From Media"), added once globally via `FileUpload::configureUsing()` in `AppServiceProvider` (`App\Support\FileUploadClearAction`). It sits outside FilePond so it's always reachable; one click empties the field. The stored file is left in storage — same as the native remove — so a mis-click is recoverable from the Media Hub. The action is only shown while the field actually holds a value.

## [2.13.1] - 2026-09-08

**Release type:** Patch/Fix Update

### Changed

- **Inbox message thread redesigned as modern chat bubbles** (WhatsApp/Messenger-style, owner-approved design pass). Messages now render as colored rounded bubbles — amber for your replies, neutral cards for the customer's — instead of plain callout boxes, with consecutive messages from the same side grouped together (no repeated spacing, and only the last bubble in a run gets the rounded "tail" corner). The conversation list now shows a colored initials avatar per contact instead of a generic channel icon. Delivery status is now a tick icon (single/double/red alert) next to the timestamp, with the status text kept for screen readers; the WhatsApp→SMS fallback badge and internal-note bubbles were restyled to match. The reply composer is now a floating pill shape. Purely visual — every Livewire binding, filter, and keyboard/screen-reader behavior on the page is unchanged.

## [2.13.0] - 2026-09-07

**Release type:** Minor Feature Update

### Added

- **"Quick Edit" on the Products list.** Each product row's actions menu (View / Quick Edit / Edit (full page)) now has a **Quick Edit** entry that opens the complete product form — every field, including the variations repeater — in a slide-over over the list, so common fixes no longer need a full page navigation. It saves through the same path as the full edit page (`App\Filament\Concerns\PersistsProductFormData`): `sale_price` still mirrors to `price`, and a changed stock value still creates a proper opening/adjustment `StockMovement` rather than a bare column write. The full-page Edit is unchanged and still available from the same menu.
- **"Bulk link products" tool for Shared Stock Pools** (super-admin). Instead of opening the single-record Create form once per product, pick a **source company** and a **linked company**, then match many of the source company's products to their counterpart in the linked company on one screen and link them all with one **Save links** — the same stage-inline-then-save shape as "Bulk Update Stock". A **Suggest matches** button pre-fills rows by exact SKU, then exact name. Every link goes through the existing `StockPoolResource::syncMembers()`, so pool mechanics (shared live stock, `StockMovementService` resync) are identical to the form; a product that already owns a pool just gets the new counterpart added to it.
- **Status quick-filter tabs on the Orders list** (Nuport-style order dashboard). Above the table: **All** plus one tab per order status (Draft / Confirmed / Processing / Completed / Cancelled / Returned / Refunded), each with a live count badge; clicking a tab shows only orders in that status. The Status column filter still works alongside the tabs for deep links and combining with other filters.

### Changed

- **Every dashboard's stat cards are now one uniform, compact size, and smaller again on phones.** The main Dashboard previously mixed sizes — Business Overview was compacted while Customer Success & Risk and Courier Health used Filament's larger default — and the Courier / Meta Ads dashboards were full-size too. A single shared rule on `.fi-wi-stats-overview-stat` in the admin theme now gives every stat card the same padding and number size app-wide (10px / 20px on desktop), with a `max-width: 640px` block shrinking them further on phones (8px / 16px). The per-widget `.zz-business-overview-stat` sizing was removed so it no longer blocks the mobile shrink; its click-to-drilldown affordance is unchanged.
- **The Products list and the Shared Stock Pools list now show which company a product's stock is pooled with.** Products gains a toggleable "Shared with" column (the other companies' names, with a "Source" marker on the pool owner) and a "Shared stock pool" filter. The Shared Stock Pools list replaces its inline `name (company)` text with dedicated **Source company** / **Pools into** / **Linked products** columns.
- **Creating an order now returns to the orders list**, instead of Filament's default jump to the new order's detail page. The "Created" toast still confirms the save.
- **Courier COD is now the full invoice total, including delivery** — every "Book courier" / "Book Steadfast" form (and the bulk action) defaults the COD to the order's `total_amount` (products − discount + VAT + shipping), no longer reduced by any advance already paid. When the order carries no shipping fee of its own, the zone delivery charge for the customer's address (`ShippingFeeService`) is added on top, so the courier always collects for delivery. New `Order::courierCodAmount()`; the field is still editable per booking.
- **On phones, the Orders list's "New order" button now sits on the same row as the page title** instead of wrapping to its own line (same `.fi-header` mobile-layout treatment the Products list already uses; desktop is unchanged).

### Fixed

- **The courier booking form now reliably pre-fills the recipient name, phone, and address from the order.** The prefill (plus the COD default) is now applied through one shared `CourierService::bookingFormDefaults()` via the action's `->fillForm()`, instead of per-field `->default()` calls that could come up blank.

## [2.12.0] - 2026-09-06

**Release type:** Minor Feature Update

### Added

- **Each AI-powered tool now has its own provider/model/API-key config, instead of one shared global AI setting.** Settings → Integrations' "AI Assistant" tab is renamed **"AI Integration"** and now holds three independent sub-tabs — **Auto Messaging** (Inbox AI reply), **Ad Assistant** (Meta Ads recommendations), and **Landing Page Builder** (Offer landing-page copy) — so a cheap fast model can be picked for chat replies, a stronger reasoning model for ad strategy, a creative-writing model for landing pages, or the same provider everywhere, without one setting overriding another. `AiSettingsService::all()`/`save()` now take an explicit tool argument (`TOOL_MESSAGING` / `TOOL_AD_ASSISTANT` / `TOOL_LANDING_PAGE`), storing each under `companies.settings->ai_tools->{tool}`. Existing companies are unaffected on upgrade: any tool not yet saved under the new per-tool shape transparently falls back (in-memory only) to the old shared `settings->ai` config, so Auto Messaging, Ad Assistant, and Landing Page Builder all keep working exactly as configured today until an admin explicitly customizes one of them. The now-redundant standalone "AI Assistant Settings" page under the CRM cluster is removed — Settings → Integrations is the one place AI is configured.
- **Settings → Integrations is now the single, complete place for WooCommerce, Payment Gateway, and Meta Pixel & CAPI configuration** — each tab used to hold only a subset, with the rest duplicated or scattered elsewhere:
  - **WooCommerce tab**: "Send test webhook" and "Sync an order now" moved from the page header into the tab body, and a new **"Sync WooCommerce"** button (the full product import, matched by SKU/slug) now lives there too — previously a separate, duplicate-credentials section on Storefront Settings, now removed from there.
  - **Payment Gateway tab**: added the ZiniPay/PayStation base-URL override fields (previously only on Storefront Settings' now-removed "Online Payments" section).
  - **Meta Pixel & CAPI tab**: expanded from just Pixel ID/access token to the *entire* configuration — consent gate, advanced matching, browser events (incl. custom selector-based events), purchase-event timing, order status events, additional Pixels/Datasets, and domain verification — plus the **Event Log & Retries** table itself, embedded directly on the page. Each section renders as its own sub-tab (same nested-tab style as the AI Integration tab) instead of a stack of collapsible sections, and both tab groups now carry an icon per sub-tab — the AI tools get new icons (chat bubble, megaphone, stacked pages), and the Meta sections reuse the exact icons the old dedicated Meta CAPI page's own section-nav used (link, shield-check, cursor, cart, arrows, queue list).
  - Manual bKash/Nagad numbers for Offer-page checkouts and other non-gateway storefront content are unaffected and stay on Storefront Settings.
- **AI Integration's provider/model fields now have a "quick setup" picker.** Each AI tool's tab (Auto Messaging, Ad Assistant, Landing Page Builder) gained two optional, searchable dropdowns above the existing free-text fields: pick a popular OpenAI-compatible provider (DeepSeek, Groq, OpenRouter, Mistral, xAI, Together AI, Fireworks AI, Perplexity, OpenAI, or a self-hosted Ollama/vLLM endpoint) to auto-fill its provider name, base URL, and API format, then pick from that provider's own short list of well-known models (searchable) to fill the Model field. Both pickers are pure convenience — the underlying Provider/Base URL/Model fields stay 100% free-text, so any provider or model not listed can still be typed in directly, same as before.
- **Offers now have "Preview" and "Open Page" buttons** — on the list and on the edit page, next to "Generate Landing Page with AI" — since there was previously no way to actually see a generated (or hand-built) landing page. "Preview" works for any offer regardless of status (admin-only, bypasses the published check, same convention as the storefront's own preview). "Open Page" is the real customer-facing URL and only appears once the offer is Published and the company has a domain set.

## [2.11.4] - 2026-09-05

**Release type:** Patch/Fix Update

### Changed

- **Every admin page now uses the full width available beside the sidebar on desktop.** The panel content was previously capped at 80rem (Filament's default `7xl`) and centred, leaving wide empty gutters on large monitors — forms, tables, and dashboards now stretch edge to edge. Phones and tablets are unaffected: their viewport is already narrower than the old cap, so only the desktop layout widens. Set once on the panel via `->maxContentWidth(Width::Full)` in `AdminPanelProvider`; the login and other simple/centred auth pages keep their own narrow width.

## [2.11.3] - 2026-09-02

**Release type:** Patch/Fix Update
### Changed

- **Inbox (CRM) smoothness improvements, web + mobile:**
  - **Conversation search now matches message bodies**, not just contact name/phone/external ID — a word from inside the thread finds the conversation (`Inbox.php`, `orWhereHas('messages')`). Search placeholder updated accordingly.
  - **The conversations list uses a "Load more conversations" button instead of page-number pagination** — each click expands the list by 30 (up to 300), growing from the top like a chat app; page-number navigation is gone.
  - **The reply composer auto-grows with its content** (up to ~8 lines, then scrolls internally) instead of a fixed one-line box with an unusable-on-mobile resize handle; the height resets whenever the conversation changes or a message is sent. Enter still sends, Shift+Enter still adds a newline.
  - **The 8-second inbox poll no longer writes to the database on every tick** — `markHumanPresent()` (the AI pause window) is now throttled to once per minute; explicit conversation selections still mark presence immediately, and `markRead()` keeps running each tick so unread badges stay accurate.
  - **The Android shell now declares `android:windowSoftInputMode="adjustResize"`** (was missing from the Capacitor default) so the viewport resizes when the soft keyboard opens — the composer no longer risks hiding behind the keyboard in the mobile app. The inbox already sizes itself with `100dvh` and re-syncs scroll, so keyboard-open now keeps the latest message and the composer visible.
  - Regression coverage: `tests/Feature/InboxSmoothnessTest.php` (body search, load-more expansion, composer reset markup, manifest resize) + updated markup assertions in `InboxPageTest`.

## [2.11.2] - 2026-08-31

**Release type:** Patch/Fix Update

### Fixed

- **A WooCommerce order whose sold quantity exceeded the ERP's own tracked stock silently never synced at all.** The stock ledger's usual negative-stock guard — correct for a manually-entered ERP sale — was also blocking every WooCommerce order sync once a product's real-world sales outpaced its ERP stock record, failing with a 500 that showed no useful reason anywhere reachable. A WooCommerce order's sale already happened on the storefront regardless of ERP stock, so a sync can no longer be blocked this way; the shortfall is now surfaced as a note directly on the order instead (e.g. "Sold below available stock, now negative on hand and needs restocking: Product X (-4).") so it's obvious the product needs restocking. Manually-entered ERP sales are still correctly blocked when stock is insufficient — unaffected.

## [2.11.1] - 2026-08-31

**Release type:** Patch/Fix Update

### Fixed

- **The new "Sync an order now" button (Settings → Integrations → WooCommerce, added in v2.11.0) failed instantly with a generic "Error while loading page" instead of opening its order-ID prompt.** A string `->action('methodName')` on a Filament action with a `->schema()` attached is wired as a raw click handler that calls the method directly with no arguments, bypassing the modal that's supposed to collect the form first — a hard PHP error against the method's required parameter. Switched to a closure action (the pattern already used by the order "Change status" action), which correctly opens the modal first.

## [2.11.0] - 2026-08-30

**Release type:** Minor Feature Update

### Added

- **Settings → Integrations → WooCommerce has a new "Sync an order now" button.** Pulls one order straight from WooCommerce's own REST API by its order ID and runs it through the exact same sync logic a real webhook delivery uses. Two uses in one: manually backfilling an order a webhook never delivered (e.g. one sent before a secret was saved), and diagnosing *why* a sync fails — any error is shown verbatim in the notification, instead of only in a server log or WooCommerce's own delivery log (which hides response bodies unless `WP_DEBUG` is on).

## [2.10.3] - 2026-08-30

**Release type:** Patch/Fix Update

### Fixed

- **A rejected WooCommerce webhook delivery now explains itself in WooCommerce's own delivery log**, instead of showing a bare status code with no way to tell why without server access. A missing/blank webhook secret (404) or a signature that doesn't match the secret saved in Settings → Integrations → WooCommerce (403) now return a small JSON body — `error: no_webhook_secret_saved` / `signature_mismatch`, plus a plain-language hint and whether the signature header even arrived — viewable from WooCommerce's own "Recent Deliveries" → response tab. No secret or signature value is ever included.

## [2.10.2] - 2026-08-30

**Release type:** Patch/Fix Update

### Changed

- **Invoice/order numbers now use a 3-digit daily sequence** (e.g. `ZMG-20260830-001`) instead of 4 digits (`-0001`). The date segment still matches the invoice's own creation date, and the sequence still restarts at `001` each day per company; the prefix stays whatever is set on Company Settings → Invoice Prefix (e.g. `ZMG`). Also fixed a latent overflow bug this change would otherwise have made much easier to hit: once a single day's count passes 999, the next number correctly continues as `-1000`, `-1001`, ... instead of wrapping the suffix back down to `-000` and re-issuing an already-used number.

### Fixed

- **Recording a payment on an order's edit page could silently undo itself.** Paid Amount/Due Amount on the edit form are read-only once an order exists (corrections go through the Payments History ledger below), but read-only alone didn't stop their stale, page-load-time values from being resubmitted — saving the order after adding a payment there could overwrite the just-recorded amount back to what it was before, so the invoice's Paid/Due lines never reflected it either. Those two fields are no longer written by the edit form at all now; only the Payments History ledger can change them. The order screen also refreshes its own Paid/Due Amount immediately after a payment is added, edited, or deleted, instead of only on the next page load.
- **Every list in the app now shows its newest record on top by default** — Orders, Customers, Products, Users, and every other list/relation table that didn't already set its own sort order previously showed the oldest record first and the newest at the very bottom (Filament's own built-in fallback sorts by ID ascending). A list that already had an intentional sort (alphabetical, drag-to-reorder, etc.) is unaffected.
- **The order "Change status" action now reports a real error instead of appearing to do nothing** if something unexpected goes wrong mid-transition (a database error, a courier-sync failure, etc.) — previously an error here could fail without any visible feedback. The success notification also now names the resulting status (e.g. "MAIN-20260830-001 is now Confirmed.") instead of a generic message.

## [2.10.1] - 2026-08-29

**Release type:** Patch/Fix Update

### Fixed

- **v2.10.0's automatic-migration deploy step broke production startup**: the `nixpacks.toml` `[start]` override hardcoded `heroku-php-apache2` as the web-server command (a Railway/Heroku-buildpack assumption), which doesn't exist on this app's actual host (a Contabo VPS running Coolify) — every deploy's new container failed its healthcheck with `heroku-php-apache2: command not found`. Coolify's own rollback-on-unhealthy behavior caught this automatically each time (the site never actually went down), but no new deploy could ever go live. The `[start]` override is removed entirely — Nixpacks' own auto-detected start command (already proven working) is used again — and `php artisan deploy:migrate` now runs via **Coolify's native "Post-deployment Command"** setting instead, which executes inside the already-healthy container rather than needing to know how it was started.

## [2.10.0] - 2026-08-29

**Release type:** Minor Feature Update

### Added

- **Deploy/migration failures now notify every active super admin** in the dashboard's own notification bell — one notification per failed step, each with a one-click "Copy Error Log" button (so several simultaneous failures show several buttons) and a "View Full Log" link. A new **Settings → Deploy Error Logs** page keeps every log permanently, so it's still recoverable after the bell notification is cleared or marked read.
- **New Settings → App Versions page**: a super admin can now roll the app back to any of its last 5 successfully-deployed versions with one click, straight from the dashboard, instead of needing to open Coolify separately. Code-only by design — it never touches the database or reverses a migration. Needs the Coolify instance URL, an API token, and this app's Application UUID entered once on the new **Settings → Deployment Settings** page.

### Fixed

- **The deploy pipeline never actually ran database migrations on a normal deploy** — `php artisan migrate` was only ever invoked by a brand-new `composer create-project`, never by a redeploy of existing code. This is what caused two real production 500s: the storefront checkout page (missing `orders.reseller_customer_id`) and the new Media Hub settings page (missing `media` table) — both migrations had shipped in code but never actually run. `php artisan migrate --force --isolated` now runs automatically, via a new `deploy:migrate` command, before the app starts serving traffic on every deploy.

### Technical Notes

- **Deploy error notifications**: new `nixpacks.toml` `[start]` command runs `php artisan deploy:migrate; heroku-php-apache2 /app/public` (`;` not `&&` — a total crash in the command must never stop the site from starting). New `App\Console\Commands\DeployMigrate` wraps `migrate --force --isolated` in a try/catch and always exits 0; a failure is handed to the new `App\Services\DeploymentErrorReporter`, which logs it, persists a new `deployment_errors` row (`App\Models\DeploymentError` — deliberately not company-scoped, same precedent as `MobileCrashReport`, excluded from `MultiCompanyIsolationTest` on purpose), and notifies every active `role = 'super_admin'` user via a new `App\Notifications\DeploymentErrorAlert` database notification (`danger()`, two actions: "Copy Error Log" using the identical Alpine `navigator.clipboard.writeText` + `$tooltip` snippet Filament's own `->copyable()` uses — extracted into `App\Support\ClipboardCopy` — and "View Full Log"). New super-admin-only `App\Filament\Resources\DeploymentErrors\DeploymentErrorResource` at Settings → Deploy Error Logs (list + view, row-level "Copy Log", delete/bulk-delete for housekeeping) is where the log survives after the notification is gone. New migration `2026_08_29_010000_create_deployment_errors_table`. New tests `DeploymentErrorReporterTest` (persistence + notification targeting/actions) and `DeployMigrateCommandTest` (a real forced migration failure via a throwaway `--path`, not a mock, confirms the command still exits 0).
- **App version rollback**: new `App\Support\CoolifySettings` (global `AppSetting`-backed, encrypted — `base_url`, `api_token`, `application_uuid` — same pattern as `FirebaseSettings`) and `App\Services\CoolifyDeploymentService`, which calls Coolify's own public API (verified directly against `coollabsio/coolify`'s source, not guessed): `GET /api/v1/deployments/applications/{uuid}` to list recent deployments (filtered to `status === 'finished'`, current live one excluded) and `POST /api/v1/applications/{uuid}/rollback` (`{"commit": "<sha>"}`) to queue a redeploy of an older commit. New `App\Filament\Pages\CoolifyDeploymentSettings` (Settings → Deployment Settings, credential form + a "Test Connection" button) and `App\Filament\Pages\AppVersions` (Settings → App Versions, lists the last 5 rollback-eligible deployments with a confirm-then-rollback button per row) — both super-admin only. New tests `CoolifyDeploymentSettingsTest`, `CoolifyDeploymentServiceTest` (via `Http::fake`, including the exact request shape sent to Coolify), and `AppVersionsPageTest` (drives the real rollback action through Livewire).
- Full `php artisan test` for both features together: 1023 passed, 0 failed. No frontend build assets changed (Blade + inline styles only), so `npm run build` was not required.

## [2.9.0] - 2026-08-29

**Release type:** Minor Feature Update

### Added

- **Invoice now shows a Parcel ID line** — between "Delivery Partner" and "Date," on both the main invoice and the courier cut-slip in the footer — whenever the booked courier reports one (e.g. Steadfast's consignment ID). Hidden entirely when the booking has no parcel/consignment reference (manual courier bookings).

### Fixed

- **Booking an order with a courier only ever surfaced the Tracking ID, never the courier's own Parcel ID** on the Order screen — even though it was already being captured and stored for every non-manual courier. The order's Courier section now shows "Parcel ID" next to "Tracking ID" right after booking.

### Technical Notes

- Parcel ID uses the existing `courier_bookings.provider_reference` column — no new migration. `resources/views/orders/partials/invoice.blade.php` reads `$order->latestCourierBooking?->provider_reference`; `OrderInfolist.php`'s Courier section gained a matching `TextEntry` right after Tracking ID. New/updated tests: `InvoiceDesignTest::test_invoice_shows_barcode_weight_delivery_partner_and_cut_slip` and `test_invoice_hides_parcel_id_when_the_booking_has_no_provider_reference`.

## [2.8.1] - 2026-08-29

**Release type:** Maintenance Update

### Technical Notes

- **Fixed a production `php artisan migrate --force` failure**: `2026_08_28_000300_create_broadcasts_tables` errored with `Duplicate column name 'opted_out_at'` when re-run, because an earlier deploy's run of it had been interrupted partway through — MySQL DDL isn't transactional, so `leads.opted_out_at` had already been added even though Laravel never recorded the migration as complete, and the retry replayed the whole thing from the top. All four schema changes in that migration's `up()` (`leads.opted_out_at`, `customers.opted_out_at`, `broadcasts`, `broadcast_recipients`) are now guarded with `Schema::hasColumn`/`Schema::hasTable`, so it can be safely re-run from any partially-applied state; `down()` is unchanged. No schema/behavior change on a fresh database. Deploy note: after this ships, re-run `php artisan migrate --force` in production — it will skip the already-applied `leads` column and create the rest. Full `php artisan test`: 1007 passed, 0 failed.

## [2.8.0] - 2026-08-29

**Release type:** Minor Feature Update

### Added

- **Storefront Settings → Site Theme → Marketplace Pro Features now has a "Wholesale buyers banner" toggle.** It controls the dark "Built for repeat and wholesale buyers / Open business account" banner shown near the bottom of the hero homepage (Marketplace Pro theme, Hero-driven template). The toggle only appears while that template is selected.

### Changed

- **The hero homepage's "Built for repeat and wholesale buyers" banner is now off by default.** It previously piggybacked on the broader "Business account callouts" toggle (default on), so every Marketplace Pro hero storefront displayed it. It now has its own dedicated toggle and starts hidden — turn on "Wholesale buyers banner" in Storefront Settings to show it again.

### Technical Notes

- New migration `2026_08_29_000100_add_marketplace_business_strip_enabled_to_storefront_settings_table` adds `marketplace_business_strip_enabled` (boolean, default `false`) to `storefront_settings`. Run `php artisan migrate --force`. Existing rows receive `false`, so the banner disappears from any live hero storefront until an owner re-enables it.
- `resources/views/storefront/themes/marketplace-pro/home.blade.php`'s hero bottom banner now gates on `marketplace_business_strip_enabled` alone (was `marketplace_business_accounts_enabled`); the campaign template's "Open a business account" card and its section wrapper still use `marketplace_business_accounts_enabled`. New regression test `StorefrontThemeTest::test_hero_wholesale_buyers_banner_is_hidden_by_default_and_shown_by_its_dedicated_toggle`. Blade + PHP only — no frontend build assets changed, so `npm run build` was not required.

## [2.7.0] - 2026-08-29

**Release type:** Minor Feature Update

### Added

- **Media Hub: a per-company image library, with "Select From Media" on every image field.** Settings → Media Hub lists every image a company has ever uploaded (grid view, newest first), lets you upload new images there directly (any number at once), and delete ones no longer needed. Every image FileUpload field across the admin panel (Products — featured/gallery/variation images, Categories, Offers, Hero Slides, Storefront Settings logos, Company logos, Company Settings logos) now has a "Select From Media" button next to it, so an image already uploaded somewhere else can be reused instantly instead of re-uploading and re-compressing it. Each company only ever sees and can pick from its own images. Existing image fields are otherwise unchanged — same helper text, same crop/aspect-ratio tools, same WebP compression. Images already uploaded before this feature existed are backfilled too — see the one-time `media:backfill` command below.

### Technical Notes

- New `media` table (`App\Models\Media`, company-scoped via `BelongsToCompany`/`CompanyScope`, added to `MultiCompanyIsolationTest`) records every image saved through the shared `App\Filament\Concerns\OptimizesUploadedImages` upload path (`Media::recordUpload()`, best-effort — never blocks the actual upload) plus anything added directly on the new Media Hub page. `mime_type`/`size` are read back from disk after `ImageOptimizerService` re-encodes the file, not from the original upload, since most raster images end up re-encoded to WebP. New `App\Filament\Concerns\SelectableFromMediaHub` trait supplies the reusable `selectFromMediaHubAction()` hint action (a Filament field-level action with its own modal `Select`, searchable, thumbnails via `allowHtml()`) wired onto every relevant `FileUpload::make(...)->image()` field; it re-resolves and re-checks the target company inside its own `action()` closure (not just when building the option list) so a tampered `media_id` can never attach another company's image. New `App\Filament\Resources\Media\MediaResource` (Settings cluster, `navigationSort = 7`, open to any panel user — same visibility as the image fields it complements) has no create/edit pages; uploading is a header action, deleting (`Media::deleteFile()`) removes the underlying object via `CompanyStorageService::locatePublic()` before the DB row. New `php artisan media:backfill` command (`App\Console\Commands\BackfillMediaHub`) registers every image already sitting in Products/Categories/Offers/Storefront Pages/Slides/Settings/Company logos before this feature existed; safe to re-run (skips paths already registered, and a `(company_id, path)` unique index on `media` backs that at the database level too), and silently skips a path whose file no longer exists on disk. Run it once after this ships — it isn't wired into any deploy step automatically. New `tests/Feature/MediaHubTest.php` and `tests/Feature/MediaHubBackfillTest.php`. OfferForm's `cover_image` field also picked up the standard `saveUploadedFileUsing(static::optimizeImageUpload())`/WebP-compression it was missing before (needed so it participates in the Media Hub the same as every other image field). No frontend build assets changed, so `npm run build` was not required.

## [2.6.1] - 2026-08-28

**Release type:** Patch/Fix Update

### Fixed

- **The homepage hero banner was cropped wrong on both desktop and mobile** — its container's height was driven by a fraction of the browser's viewport height, completely unrelated to the banner image's actual width:height ratio. On any window where those didn't line up (most of them), the banner zoomed in and cut off the image's own headline text and bottom content. The banner now sizes itself from its declared ratio (3:1 desktop, ~2.8:1 mobile — the same ratio the Hero Slides admin form already tells you to upload at), and on mobile it now shows the full image with no cropping ("fit to screen") instead of cutting off the edges.

### Technical Notes

- `resources/css/app.css`'s `.storefront-image-banner` rule changed from `height: calc(100vh / N)` to `aspect-ratio` (`45 / 16` mobile, `3 / 1` desktop from `1024px` up), matching `App\Support\StorefrontThemeRegistry::BANNER_SPECS`. Mobile now uses `object-fit: contain` on the slide `<img>` (was `object-fit: cover` via a Tailwind class on the element, removed so the CSS above is the single source of truth); desktop keeps `cover`. Also made the ratio itself explicit at the front of each Hero Slides form field's helper text (`StorefrontThemeRegistry::BANNER_SPECS['note']`) instead of only mentioning it mid-sentence. Regression test `StorefrontBannerTest::test_banner_height_uses_the_requested_viewport_ratios` (which had locked in the old, buggy vh-based CSS) replaced with `test_banner_height_is_driven_by_its_declared_aspect_ratio_not_viewport_height` and `test_banner_fits_to_screen_on_mobile_without_cropping`.

## [2.6.0] - 2026-08-28

**Release type:** Minor Feature Update

### Added

- **Lead CRM: WhatsApp→SMS fallback, Bulk/Broadcast messaging, and follow-up auto-reminders.** Researched whether a third-party unified-messaging platform (sent.dm) was worth integrating; found that standard Bangladeshi bulk SMS routes are one-way (customers cannot reply to a masked sender ID — a real telecom-route limitation, not a gateway API gap), so true two-way SMS threading was out of scope and the existing WhatsApp Inbox stays the one real two-way channel. Built the three approved pieces natively instead, on top of the existing WhatsApp Cloud API integration and the generic HTTP SMS gateway already used for OTP/abandoned-cart (no new outside platform, no extra monthly fee):
  - **WhatsApp → SMS fallback**: when an outgoing WhatsApp message fails for any reason (session window closed, invalid token, Meta outage), the Inbox now automatically retries the same message over the company's configured SMS gateway and logs it in the same conversation thread with a **"via SMS"** badge, instead of leaving the customer with nothing.
  - **Bulk/Broadcast messaging** (new **CRM → Broadcasts**): send a WhatsApp (Meta-approved template only — never free-form, since this is a cold business-initiated send) and/or SMS message to a filtered list of Leads (by status/source) and/or Customers in one go, with automatic opt-out exclusion (new "Opted out" flag on Leads/Customers) and phone-number dedupe so nobody with the same number is messaged twice. The WhatsApp template picker only ever lists templates Meta has actually approved for the selected Chat Channel — fetched live, never hand-typed — so a broadcast can't be queued against a template that would just fail to send.
  - **Follow-up auto-reminders**: when a lead's **Next Follow-up** date/time arrives, the assigned staff member is notified in-app/push immediately (reusing the existing business-alert notification system), and — when enabled per company — the lead is also messaged automatically over WhatsApp (approved template) with the same SMS fallback, using admin-configurable message content (never hardcoded copy). Runs every 15 minutes via the scheduler; moving a lead's follow-up date re-arms its reminder.

### Fixed

- **Storefront Settings → Integrations tab silently stopped showing "Sync WooCommerce" (and Navigation Menus/SEO moved to the wrong tab)**: caught while adding the new Lead Follow-up Reminders section above. Which tab a section belongs to is driven by a positional index→tab-key array parallel to the list of top-level form sections; inserting a new section without updating that array shifted every later section's tab assignment by one. Fixed by updating the array to match, and this positional design is worth knowing about for anyone adding a section to this form later.

### Technical Notes

- Three new migrations ship with the CRM round above and must run before those features work: `add_delivery_channel_to_conversation_messages_table` (nullable `delivery_channel` on `conversation_messages`), `add_lead_follow_up_reminders_to_storefront_settings_and_leads` (`lead_follow_up_reminders_enabled` on `storefront_settings`, `follow_up_reminded_at` on `leads`), and `create_broadcasts_tables` (`broadcasts` + `broadcast_recipients` tables, plus `opted_out_at` on `leads` and `customers`). Run `php artisan migrate --force` before relying on any of the three features.
- New scheduled command `crm:send-follow-up-reminders` (registered in `bootstrap/app.php`, `everyFifteenMinutes()`) needs the existing Laravel scheduler cron entry already running in production — no new cron setup required. Broadcast sending (`App\Jobs\SendBroadcastJob`) goes through the existing queue connection like every other queued job in this app — a broadcast queued while no queue worker is running simply sits at `status: queued` until one picks it up.
- `Broadcast` is added to `MultiCompanyIsolationTest`'s per-model scope contract; `BroadcastRecipient` is deliberately excluded, scoped only through its parent `Broadcast` — same pattern as `ConversationMessage` under `Conversation`.
- Full `php artisan test`: 831 passed against this branch's base commit; the 52 failures present there are pre-existing and unrelated (every one is a Filament admin-page-render test failing on `Vite manifest not found` — this sandbox never had `npm install`/`npm run build` run in it) — confirmed identical before and after this round's changes via a clean `git stash -u` baseline diff. No frontend build assets changed (only a small Blade badge in `resources/views/filament/pages/inbox.blade.php`), so `npm run build` was not required for this round. Re-verified after merging in `main` (v2.5.0) — see this round's merge note in `UPDATE_NOTES.md` for the post-merge test run.

## [2.5.0] - 2026-08-28

**Release type:** Minor Feature Update

### Added

- **Vouchers/Accounts/Expenses summary cards now open a preview popup when clicked** — the same drilldown-modal UX the main Dashboard's own stat cards already use. A Voucher card shows the matching vouchers, an Account card shows that account's recent transactions, an Expense category card shows that category's recent expenses — each with a "See all" link through to the full filtered/record page.

### Fixed

- **A WooCommerce order with even one unmatched line item showed a total of just the shipping fee, instead of what the customer actually paid** — e.g. an order that should have totalled ৳1,870 (item ৳1,750 + shipping ৳120) showed only ৳120, because the unmatched item's price was silently missing from the calculation. The order's total (and subtotal, and due amount) now always reflect WooCommerce's full reported amount, even when a line item couldn't be matched to an ERP product — the Note still flags exactly which item(s) were skipped, same as before.

### Technical Notes

- New shared trait `App\Filament\Concerns\HasDrilldownStatCards` (used by `VoucherSummaryWidget`, `AccountSummaryWidget`, `ExpenseCategorySummaryWidget`) provides the modal-building plumbing; `BusinessOverview`'s own existing drilldown code on the main Dashboard is untouched. All three widgets now reuse `BusinessOverview`'s view (`filament.widgets.business-overview` — genuinely generic markup, its docblock updated to say so) so the `<x-filament-actions::modals />` these mounted actions need actually render. Since Account/Expense have a variable number of cards (one per account/category, not a fixed set), each card's `wire:click` embeds only that record's numeric ID (never its name/text, which would need JS-string escaping) — the specific record is then re-resolved inside the modal's own closures via Filament's `array $arguments` closure-injection (any Action closure parameter literally named `$arguments` receives the mounted action's arguments). Voucher/Fund Transfer cards (a fixed, known set) pass a `type`/`bucket` or `status` argument the same way. New tests: 5 in `DashboardSummaryCardsTest` covering all three widgets' modal content.
- **Unmatched-item total bug root cause**: `subtotal`/`total_amount` are always recalculated by `OrderWorkflowService::sync()` purely from the OrderItems that actually exist (triggered by every `OrderItem`'s own `saved`/`deleted` hook) — correct in general (it's the one place totals get computed everywhere in the app), but a line item that never became an `OrderItem` (because no ERP product matched it) was invisible to that calculation, silently vanishing from the total. `WooCommerceOrderSyncService::syncItems()` now returns which line items were unmatched; when any were, `reconcileTotalsWithWooCommerce()` recomputes `subtotal`/`total_amount`/`due_amount` using the exact same formula `OrderWorkflowService::sync()` uses, except the subtotal is summed directly from *every* line item WooCommerce reported (matched or not), not only the ones that became a real `OrderItem` row. New tests: `test_an_order_with_an_unmatched_item_still_gets_woocommerces_full_total_not_just_shipping`, `test_totals_still_reconcile_correctly_when_only_some_items_are_unmatched`.

## [2.4.3] - 2026-08-28

**Release type:** Patch/Fix Update

### Changed

- **Vouchers/Accounts/Expenses summary cards are more compact** (owner report, with a screenshot of the Vouchers page on mobile): the 3-word Voucher labels ("Credit Voucher - Requested") were wrapping to 3 lines, and the big count/amount below them made every card awkwardly tall. Card title and value text are both smaller now, on all three pages.

### Fixed

- **The "Print" button on the invoice page still didn't open a print dialog inside the ZamZam Dashboard Android app**, even after it was fixed for mobile browsers. Now works from inside the app too.

### Technical Notes

- **Summary card sizing**: same technique the main Dashboard's own stat cards already use (`.zz-business-overview-stat` in `theme.css`) — a CSS class added to each `Stat` via `->extraAttributes(['class' => '...'], merge: true)`, scoped so it only affects these three widgets' cards. New `.zz-voucher-summary-stat`/`.zz-account-summary-stat`/`.zz-expense-summary-stat` rules shrink the label to 11px and the value to 18px (down from Filament's defaults of 14px/30px) and tighten padding to 10px. New test: `DashboardSummaryCardsTest::test_voucher_summary_cards_use_the_compact_stat_style`, plus assertions added to the existing Account/Expense summary tests.
- Root cause of the print-inside-the-app issue: yesterday's mobile-browser fix made `window.print()` work correctly in real mobile browsers, but Android's own WebView (which is what the app's UI actually runs inside) never implements `window.print()` at all — calling it there was always a silent no-op, browser fix or not. Added a native bridge (`android/app/src/main/java/com/zamzamint/erp/PrintBridge.java`, registered by `MainActivity` as `window.ZzPrintBridge`) that drives Android's own `PrintManager`/`WebView.createPrintDocumentAdapter()` — the standard way a WebView page is printed natively. Both invoice print pages now call `window.ZzPrintBridge.print()` when it's present (i.e. running inside the app) and fall back to `window.print()` otherwise (real browsers, unaffected). **Requires a new Android app build to reach devices** — unlike a web-only fix, this doesn't take effect just by deploying the server; the owner needs to install the next app update once built. New tests: `InvoiceDesignTest::test_print_button_falls_back_to_the_native_android_bridge_inside_the_app`, plus an assertion added to `OrderBulkPrintTest`.

## [2.4.2] - 2026-08-27

**Release type:** Patch/Fix Update

### Changed

- **Marketplace Pro homepage hero is now full width**: the two "Ready to order" category cards that used to sit beside the hero banner are removed (owner request), so the banner fills the row on every screen size instead of sharing it. Mobile layout is unaffected — the hero was already single-column below tablet width.
- **Trust/service strip (Nationwide dispatch, Easy replacement, Business pricing, Secure payment) is now a horizontally-scrollable strip on mobile** instead of 4 stacked rows — swipe/scroll sideways to see all four; switches to a proper grid from tablet width up, unchanged from before.

### Fixed

- **Tapping "Print" on the order invoice page did nothing on mobile** — the device's print dialog never opened, even though the same button worked fine on desktop. Fixed on both the single-order invoice and the bulk-invoice print pages.
- **"See all"/"View all" links across the Marketplace Pro homepage showed a corrupted, meaningless character instead of an arrow** (a text arrow that had been through a character-encoding bug at some point, rendering as garbled text like `â†'` instead of `→`). Replaced with a real arrow icon (SVG) everywhere it appears on this theme's homepage.
- **The same encoding corruption was found and fixed across the rest of the storefront**, not just the homepage: the storefront offers page, Noor Solar Energy theme's homepage, checkout, cart, product page, order tracking page, and contact page all had various punctuation (em dashes, en dashes, smart quotes, ellipses, checkmarks) silently rendering as garbled text. Also recovered a Bengali confirmation message on the storefront's post-checkout "thank you" page that had been corrupted the same way, using the last known-good version from git history rather than reconstructing it.

### Technical Notes

- **Mobile print fix root cause**: `window.print()` was called from inside a `setTimeout()` (added earlier to give `window.focus()` a moment to take effect). Most mobile browsers (Android Chrome, iOS Safari, and in-app WebViews) only treat `window.print()` as a genuine response to the user's tap — and open the native print dialog — when it runs *synchronously* inside the click event itself; a delay of any length, even 50ms, drops that "user activation" flag and the call is silently ignored. Desktop browsers are more lenient about this, which is why it worked there and went unnoticed. Fixed in both `resources/views/orders/print.blade.php` and `resources/views/orders/print-bulk.blade.php` by calling `window.print()` immediately in the click handler; the separate auto-print-on-page-load paths (`?print=1` on the single invoice, always-on for bulk print) aren't a click gesture either way, so they keep the short delay. New regression test `InvoiceDesignTest::test_print_button_calls_window_print_synchronously_on_click_for_mobile_browsers`.
- Root cause of all the corrupted text above: at some point, UTF-8 bytes in these files were decoded as Windows-1252/Latin-1 and re-saved as UTF-8, corrupting every non-ASCII character (arrows, dashes, quotes, checkmarks, and — in one file — Bengali script) into a garbled multi-character sequence. Fixed via a byte-level script that detects any character run which round-trips cleanly back to valid UTF-8 through a CP1252 re-encode (the signature of this exact corruption) and restores the original character; verified programmatically (not by eye — the same class of encoding confusion can also affect how a terminal *displays* text, so visual inspection alone isn't reliable here) that zero such runs remain in any of the 9 affected files afterward. `resources/views/storefront/offers/thank-you.blade.php`'s Bengali text specifically was restored using `git show` against the last commit before the corruption (`3ab35b5e`), byte-verified as an exact match rather than reconstructed blind.
- New `resources/views/storefront/partials/arrow-right-icon.blade.php` (reusable SVG, matches the existing hand-rolled stroke-icon style already used by the trust-strip icons) + new `.marketplace-link-arrow` CSS class for icon/text alignment.
- `.marketplace-hero-grid`/`.marketplace-promo-card` CSS removed (dead code — no longer referenced by any Blade view after the promo cards were removed); `.marketplace-info-card` (a different, still-used card sharing some of the same rules) kept intact.
- `.marketplace-trust-grid` default is now `display:flex; overflow-x:auto` with `scroll-snap`, switching to `display:grid` at the existing `sm`/`lg` breakpoints (unchanged from before).
- **Owner also asked to hide the "Built for repeat and wholesale buyers / Open business account" card for retail-only companies** (e.g. ZamZam Gadget) while keeping it for wholesale-only companies (e.g. ZamZam International) — this already exists as a per-company toggle (Storefront Settings → Marketplace Pro Features → "Business account callouts"), so no code change was needed; just needs switching off for the retail company via the dashboard.
- New tests: `StorefrontThemeTest::test_marketplace_hero_is_full_width_without_promo_cards_and_uses_a_real_arrow_icon`, `test_marketplace_trust_strip_scrolls_horizontally_on_mobile`.
- Found (not fixed here — unrelated, admin-only, flagged separately): `resources/views/filament/pages/inbox.blade.php` has its own, different single-byte encoding issue.

## [2.4.1] - 2026-08-27

**Release type:** Patch/Fix Update

### Fixed

- **A WooCommerce order could sync with no items and a zero subtotal, even though the order itself (customer, date, status, delivery charge) came through fine.** Line items were only ever matched to an ERP product by exact SKU — a real WooCommerce catalog that doesn't set a SKU on every product (common) meant every line item on that order was silently skipped. Line items now also match by product name when the SKU doesn't match (or is blank), the same fallback strategy WooCommerce product import already uses.
- **A skipped/unmatched line item was only ever visible in the server log, invisible to whoever opened the order.** The order's Note field now lists exactly which item name(s)/SKU(s) couldn't be matched, right on the order itself.

### Technical Notes

- `WooCommerceOrderSyncService::syncItems()`: SKU match first, then falls back to `Product.slug` matched against `Str::slug($lineItem['name'])` — the exact normalization `WooCommerceImportService::importProduct()` already applies when a product doesn't have its own WooCommerce-supplied slug, so a product previously imported through that flow matches correctly here too. Still skips (and now also notes) anything matching neither. New tests: `test_a_line_item_with_a_blank_sku_matches_by_product_name_instead`, `test_an_unmatched_line_item_leaves_a_visible_note_on_the_order`.

## [2.4.0] - 2026-08-27

**Release type:** Minor Feature Update

### Added

- **WooCommerce order status changes made in the ERP now push back to WooCommerce**: previously the sync only worked one way (WooCommerce → ERP via webhook) — marking a WooCommerce-sourced order Completed/Cancelled/etc. in the ERP now updates that same order's status on the WooCommerce site too, via WooCommerce's own REST API using the already-configured Consumer key/secret (Settings → Integrations → WooCommerce). Runs as a background job, so a slow or unreachable WooCommerce site never delays saving the order in the ERP; a failed push is logged, not surfaced as an error on the order.
- **One-click copy icons for phone numbers and courier tracking IDs**: the Customers list and Orders list both show a copy icon next to the customer's phone number, and the Orders list shows one next to the courier tracking ID once a courier has been booked (blank before that).

### Fixed

- **WooCommerce-synced orders never carried the store's real delivery charge or total — both silently came out wrong.** A brand-new order created from a WooCommerce webhook had its shipping fee immediately overwritten by the ERP's own zone-keyword fee calculator (meant for admin-created orders, which don't already have a real shipping charge) — so the order's shipping fee, and therefore its total, reflected an ERP-guessed amount instead of what the customer actually paid for delivery on the storefront. Item-level prices synced correctly already; if those still look wrong after this fix, check the Laravel log for "no matching product SKU" warnings — an item whose WooCommerce SKU doesn't exactly match an ERP product SKU is skipped rather than guessed at.
- **Order edit page's "Courier Fraud Check" button and its result (a multi-column courier stats table) were squeezed side by side into an overlapping, unreadable mess on phone screens.** They now stack — the button on its own line, the result full-width below it — on anything narrower than tablet width, and sit side by side again from tablet width up, matching every other responsive layout already in the admin panel.

### Technical Notes

- `Flex::make([...])` in `OrderForm.php`'s fraud-check row had no responsive breakpoint (`fi-from-default`, Filament's own default, is *always* a row, full width down to phone-narrow). Added `->from('md')`, which Filament's own `flex.css` only switches to a row (`md:flex-row`) at tablet width and up, `flex-col` below it. Also wrapped the courier stats table in its own `overflow-x:auto` container and added `font-variant-numeric:tabular-nums` to its numeric columns as a second line of defense on anything still too narrow, and for cleaner number alignment generally. New regression test `SalesOrderTest::test_order_edit_form_stacks_the_fraud_check_result_below_the_button_on_mobile` (asserts the `fi-from-md` class actually renders — the earlier code loaded fine and wasn't caught by any existing test precisely because nothing asserted on responsive behavior, only that the page loads).
- **Shipping-fee overwrite root cause**: `Order::creating()` auto-fills `shipping_zone`/`shipping_fee` from `ShippingFeeService::feeFor()` whenever `shipping_zone` is still `null` on a brand-new order — correct for admin-created orders (no shipping charge exists yet to preserve), wrong for a WooCommerce-synced order, which always already has a real `shipping_total` in its webhook payload. `WooCommerceOrderSyncService::upsertOrder()` now sets `shipping_zone` (via the same `ShippingFeeService::determineZone()`, for reporting/filtering consistency, defaulting to `''` rather than `null` when nothing matches) *before* filling in WooCommerce's real `shipping_fee`, so the `creating` hook's condition (`shipping_zone === null`) never fires and the real value survives. New regression test `WooCommerceOrderWebhookTest::test_shipping_fee_and_total_use_woocommerces_real_values_not_the_erp_zone_calculator` configures a company whose ERP-calculated fee would differ from WooCommerce's real one if the bug were still present — confirmed it fails without the fix (reverted the fix locally, re-ran, got the exact "70.0 is not 150.0" mismatch) before restoring it. The existing webhook tests never caught this because their fixture's `shipping_total` happened to be `'0.00'`, which coincidentally matches the ERP calculator's own zero-fee fallback for an unconfigured shipping zone.
- **ERP → WooCommerce status push**: new `App\Jobs\PushWooCommerceOrderStatusJob` (queued) calls `WooCommerceOrderSyncService::pushStatusToWooCommerce()`, which `PUT`s `{status}` to `/wp-json/wc/v3/orders/{id}` using the same Consumer key/secret Basic Auth pattern `WooCommerceImportService` already uses. New `WooCommerceOrderSyncService::REVERSE_STATUS_MAP` (Order status → WooCommerce status — a reasonable default, not a confirmed business rule, same caveat as the existing forward `STATUS_MAP`; ERP's extra `confirmed` state and WooCommerce's simpler status list both collapse to WooCommerce `processing`, ERP `returned` maps to WooCommerce's closest equivalent `refunded`). Triggered from `Order::booted()`'s `updated()` hook whenever `status` changes on an existing WooCommerce-sourced order. **Loop prevention**: a new transient (non-persisted) `Order::$suppressWooCommercePush` property, set by `WooCommerceOrderSyncService::upsertOrder()`/`markCancelled()` before saving a status change that just arrived FROM a webhook — without it, every WooCommerce → ERP sync would immediately queue a push of that same status straight back to WooCommerce. New `tests/Feature/WooCommerceOrderStatusPushTest.php` (6 tests: queues correctly for a WooCommerce order's status change, never queues for a non-WooCommerce order, never queues for a webhook-originated change, sends the correctly-mapped status via the REST API, and two safe-no-op cases — missing credentials, non-WooCommerce source).
- **Copy icons**: all three are Filament's native `TextColumn::make(...)->copyable()` — no custom JS. `CustomersTable`'s existing `phone` column gained it directly. `OrdersTable` gained two brand-new columns that didn't exist before: `customer.phone` (`->toggleable()`, placeholder `-`) and `latestCourierBooking.tracking_id` (`->toggleable()`, placeholder `-`, blank until a courier is booked) — both eager-loaded via `modifyQueryUsing()` (added `latestCourierBooking` to the existing `with([...])` alongside `customer`/`latestFraudCheck`/`latestRiskReview`) to avoid N+1 queries. New `tests/Feature/CopyableColumnsTest.php` (4 tests) asserts the `fi-copyable` CSS class Filament renders when `->copyable()` is active actually lands on the phone/tracking-ID values, not just that the pages load.

## [2.3.1] - 2026-08-27

**Release type:** Patch/Fix Update

### Changed

- **Admin sidebar menu order rearranged** (owner request): Dashboard, CRM, Sales, Inventory, Purchasing, Courier, Resellers, Ads, Finance, Investments, Reports, Company Management, Site, Settings. **Customer Success now lives under the Courier menu** (Risk Profiles, Blacklists, Risk Reviews, Risk Events, Risk Settings) instead of its own separate top-level entry — courier delivery outcomes are this module's primary risk signal, so keeping it alongside Courier's other pages was the owner's preference over a standalone menu.

### Technical Notes

- Reordering was a pure `navigationSort` change across the 13 `NavigationCluster` subclasses in `app/Filament/Clusters/` — no routes changed for anything except Customer Success. The `CustomerSuccess` cluster class is now deleted; its 5 owning Resources/Pages (`CustomerRiskProfileResource`, `CustomerBlacklistResource`, `CustomerRiskReviewResource`, `CustomerRiskEventResource`, `CustomerRiskSettings`) now declare `$cluster = Courier::class`, moving their URLs from `/admin/customer-success/*` to `/admin/courier/*` (updated in `tests/Feature/CustomerRiskTest.php`). Verified the full requested order programmatically (`getNavigationSort()` per cluster) before shipping.
- **`tests/Feature/ReleaseNotesTest.php` made version-agnostic**: 6 assertions hardcoded the exact version/date/type-label string (`v2.2.0`, `Released 2026-08-26`, `Minor Feature`), so the very next `cut-release` CI run (this round auto-cut `v2.3.0`) broke this test file again — the same class of maintenance the auto-cut feature was supposed to reduce, just one file over. All 6 now read the expected value from `AppRelease::latestPublished()` at test time instead of a literal, so this file no longer needs a manual touch on every future release cut.

## [2.3.0] - 2026-08-27

**Release type:** Minor Feature Update

### Added

- **Dashboard-style summary cards on Vouchers, Accounts, and Expenses**: each page now shows a row of clickable stat cards above its table (5 per row on desktop, 2 on mobile — same responsive grid as the main Dashboard's own stat cards). **Vouchers**: one card per Credit Voucher / Debit Voucher / Fund Transfer × Requested / Approved / Rejected combination, each showing that combination's live count; clicking a card filters the page down to exactly those records. **Accounts**: one card per account (every account, not just active ones) showing its live current balance; clicking a card opens that account's own page. **Expenses**: one card per expense category (every category) showing its total spend to date; clicking a card opens that category's own page.
- **Storefront Footer Builder** (Storefront Settings → Footer Builder): the footer is no longer one fixed block of markup — an admin Repeater now lets a company add, remove, and reorder footer sections (**Brand & about text**, **Quick links**, **Contact info**, **Social links**, and a new **Bottom bar** with a copyright line supporting `{year}`/`{company_name}` tokens plus a row of legal links pointing at any published Storefront Page). Every existing company keeps its current footer exactly as before (a documented default block set, one-time backfilled via a new `storefront:seed-default-footer-blocks` command) until an admin opens the builder and changes it. **Social links** (Facebook/Instagram/YouTube/TikTok/X/LinkedIn/WhatsApp) are configured once per company and only render where the Social links block is placed.
- **Reseller program can now be turned off per company** (Storefront Settings → Footer Builder → "Show reseller program"): switches off "Become a reseller"/"Reseller status" from the account menu and footer everywhere. The reseller page and dashboard still work at their direct URL — only the promotional links are hidden, so an in-progress application isn't cut off.
- **Company Google Maps link**: Company settings now has an optional "Google Maps link" field — paste a location's real Google Maps "Share" link and the Contact page's "Find on Map" button uses it exactly; leaving it empty keeps the previous auto-generated (address-text-search) link.
- **Header/mobile navigation now shows each category's own icon** next to its label when a menu item links to a category — reuses the existing Category icon picker (Filament admin), so the homepage category cards and the header menu always stay in sync from one source of truth, no separate icon setup.
- **Product descriptions now support rich formatting** (headings, bold, lists, images) — the same editor already used for Storefront Pages, replacing the old plain-text box. Existing plain-text descriptions keep rendering exactly as before.
- **Hero banner slides now show their configured heading/subheading/button text as an overlay** on the image when at least one of those fields is filled in — previously the Storefront Slide form collected this content but the public banner silently never displayed it. A slide with none of these fields set still renders as image-only, unchanged.
- Homepage "Top categories" section (Built-in theme) redesigned into a card grid ("Shop by category") with a hover lift/shadow, replacing the small round-icon horizontal scroller; the related-products grid on a product page is now 2 columns on mobile / 5 on desktop (was a 1-column mobile default).
- Storefront header/footer are now **theme-pluggable**: every storefront page resolves its layout through `StorefrontThemeRegistry::layoutView()` instead of a hardcoded `storefront.layout` reference. All themes currently share the same header/footer (nothing looks different today), but a future theme can ship its own by setting one `'layout'` entry in the registry — no per-view changes needed anywhere else.
- Storefront login/password-reset OTP emails can now be sent through a **company's own SMTP account** (Storefront Settings → Customer Notifications & Reminders → new "Email (SMTP)" fields), matching the existing per-company SMS gateway pattern. Falls back to the server's default mailer when left empty, so nothing changes for companies not using this.
- **Dashboard stat cards are now clickable drilldowns**: every card on the main Dashboard's Business Overview (Today Sales, Storefront Pending, Today Purchases, Customer/Supplier Payments, Today Expenses, Customer Due, Supplier Payable, Low Stock Items, Coming Soon Products) opens a popup listing the actual records behind that number, with a "See all" link into the matching filtered list page when one exists. Account Balance still links straight to Accounts (there's no "list" for a single balance). Customer Success & Risk and Courier Health's stat cards are now also 2 columns on mobile (was 1), matching Business Overview's existing mobile layout.
- **WooCommerce order sync (WooCommerce → ERP, via webhook)**: a company's real WooCommerce store can now push orders into the ERP automatically as they're placed, updated, or cancelled — Settings → Integrations → WooCommerce gained a webhook secret (with a generate button) and a delivery URL to paste into WordPress (WooCommerce → Settings → Advanced → Webhooks). Each webhook delivery is HMAC-signature-verified, upserts idempotently (a redelivered webhook updates the same order instead of duplicating it), matches line items to ERP products by SKU (an unmatched SKU is skipped with a logged warning rather than failing the whole order), and reuses the same customer-by-phone matching the storefront checkout already uses. WooCommerce order status is mapped to an ERP order status by a reasonable default table — **flagged for the owner to confirm/adjust** before relying on it for anything status-sensitive (see Technical Notes). Product import (WooCommerce → ERP) is unchanged and unaffected.

### Fixed

- **A cancelled or expired payment-gateway return showed a raw "403 Forbidden" page instead of returning the customer to checkout**: the signed-URL check only ignored the `invoice_number`/`trx_id` params a gateway appends on return, so any other extra param a gateway's own cancel/timeout redirect might add (or a stale/expired link) failed the signature check with a bare `abort(403)`. It now redirects back to checkout with a friendly "Your payment session ended or was cancelled" message instead; real payment finalization is unaffected — it still always requires a separate, successful server-to-server verification call to the gateway, the signature is only an extra guard against guessing a payment's URL.
- **A real production WooCommerce webhook delivery could be rejected with "Delivery URL returned response code: 403" even with a correctly-saved secret, and gave no way to tell why.** Investigated live against the real production endpoint: the app/route/signature-check logic itself is correct, but two real bugs were found and fixed along the way. (1) A rejected webhook delivery (wrong secret, no secret configured) left no trace in the server log at all, making a real mismatch impossible to diagnose after the fact — every rejection now logs the company, topic, and both the computed and received HMAC signatures (safe to log — neither reveals the secret). (2) **Settings → Integrations → WooCommerce** gained a **"Send test webhook"** button: it sends a real, correctly-signed request to this company's own webhook URL using the secret actually saved in the database (not whatever's unsaved in the form) and reports back instantly whether it was accepted — this is the fastest way to tell a genuine code/network problem apart from the secret pasted into WooCommerce simply not matching what's saved here (the most common real-world cause of this class of error) without needing WooCommerce's own delivery logs or a manual `curl` request.

### Security

- **Product descriptions now render through Filament's `RichContentRenderer` instead of raw, unsanitized HTML output**: the new rich-text description field is rendered on the public product page with `{!! !!}`-equivalent trust, matching how Storefront Pages already render their rich content — using the sanitizing renderer (not a plain `{!! $description !!}`) closes a stored-XSS gap where any `<script>` that ever ended up in that column (a bug in a future import/bulk-update path, for example) would otherwise execute on every visitor's browser.
- **Backend/admin hardening pass** (09_DASHBOARD_WOOCOMMERCE_SECURITY_PLAN.md step 3 — see that plan for the full "what's actually possible on the web" reasoning behind these):
  - A storefront visitor can no longer see a raw PHP stack trace/file paths if the production server's `.env` is ever misconfigured with `APP_DEBUG=true` — a new defense-in-depth exception renderer shows a plain, brand-neutral error page (403/404/419/429/500) for any full-page storefront request instead, regardless of the `.env` setting. This never touches the admin panel, Livewire's own AJAX updates, or API/webhook JSON responses — those keep exactly their normal (Filament's own / Laravel's own) error handling. **The real fix is still operational**: the owner must confirm the production server's actual `.env` has `APP_DEBUG=false` (Coolify env variables) — this code change is a safety net, not a substitute for that.
  - `public/robots.txt` now disallows `/admin` and `/livewire` from search-engine crawling (was allowing everything).
  - Every response now carries baseline security headers: `X-Frame-Options: SAMEORIGIN` (clickjacking protection, notably for the `/admin` login page), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: geolocation=(), microphone=(), camera=()`. A Content-Security-Policy was deliberately **not** added — a wrong CSP can silently break the whole site (inline scripts, CDN-loaded Alpine/Chart.js/pixel scripts) and needs its own carefully-tested pass.
  - Verified (no code change needed): the `/admin` login page already rate-limits to 5 attempts and already gives the same generic failure message with a constant-time response whether the account exists, the password is wrong, or the account can't access the panel — confirmed by reading Filament's own `Login` page source, which wraps the whole check in a `Timebox` specifically to prevent this kind of enumeration.

### Technical Notes

- The new summary-card widgets (`VoucherSummaryWidget`, `AccountSummaryWidget`, `ExpenseCategorySummaryWidget`) are hand-built on top of Filament's native `StatsOverviewWidget` (same `getColumns() => ['default' => 2, 'lg' => 5]` shape `BusinessOverview`'s Dashboard cards already use) with `$isLazy = false` — Filament widgets AJAX-load after first paint by default, but these cards are the point of the page, matching `FundTransfersWidget`/`ProductStatsOverview`'s own already-shipped `$isLazy = false`. Voucher card click-through deliberately avoids Filament's native `tableFilters` query-string hydration — `CourierMerchantDashboard`'s docblock already records that being confirmed broken on a cold GET in this Filament install — and instead reuses `ProductStatsOverview`'s "Total Shortage" pattern: a plain query string read via Livewire `#[Url]`-bound properties (`ListVouchers::$cardType`/`$cardStatuses`, `FundTransfersWidget::$ftStatus`) applied as an ordinary `->where()`/`->whereIn()`. Account/Expense-Category cards instead link straight to that exact record's own resource `view` page (`AccountResource`/`ExpenseCategoryResource`), needing none of that workaround. Voucher's "Requested" bucket groups `pending` + `verified` together (Credit vouchers normally pass through `verified` before `approved`; Debit vouchers can go straight from `pending` to `approved`) rather than showing `Voucher::STATUSES`' full 5 values. No schema changes; no new company-owned models (nothing to add to `MultiCompanyIsolationTest`).
- New columns: `storefront_settings.footer_blocks`/`social_links`/`reseller_program_enabled` and `companies.google_maps_url`, all nullable/defaulted so existing rows behave exactly as before. `StorefrontSetting::footerBlocks()` returns the stored blocks or a documented `DEFAULT_FOOTER_BLOCKS` constant when null; `php artisan storefront:seed-default-footer-blocks` one-time backfills that default set into every company still on `NULL` (idempotent — only touches `whereNull('footer_blocks')` rows, safe to re-run). No new company-owned models — all four columns live on `Company`/`StorefrontSetting`, both already `BelongsToCompany`-scoped, so `MultiCompanyIsolationTest` needs no new entries.
- All 22 storefront Blade views that previously hardcoded `@extends('storefront.layout')` now call `@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))`; `layoutView()` falls back to `'storefront.layout'` for any theme without its own entry, so this is a no-op today for Built-in/Marketplace Pro/Noor Solar.
- `App\Services\StorefrontNotificationService::companyMailer()`'s return type is the `Illuminate\Contracts\Mail\Mailer` interface, not the concrete `Illuminate\Mail\Mailer` class — `Mail::mailer($name)` returns Laravel's `MailFake` under `Mail::fake()` in tests, which only implements the interface, not the concrete class.
- Added `@tailwindcss/typography` (Tailwind v4 `@plugin` directive in `resources/css/app.css`) for the `prose`/`prose-invert` classes now used to render rich product descriptions.
- `08_STOREFRONT_UX_FIXES_PLAN.md`'s item 1 ("website ক্যাটাগরি বসানো") was operational/data-entry, not code — no change needed. Item 6 was merged into item 3's footer builder (its "bottom bar" block). New `tests/Feature/StorefrontUxFixesTest.php` plus targeted updates to `StorefrontSlideTest`/`StorefrontBannerTest` (heading/subheading/CTA overlay now expected to render, previously asserted absent) and `StorefrontPaystationPaymentTest` (signature-failure return now expects a redirect, previously expected a raw 403).
- **Dashboard drilldown** (`App\Filament\Widgets\BusinessOverview`) stays a `StatsOverviewWidget` subclass (not a bespoke `Widget`) so the grid/spacing/responsive columns stay byte-identical to every other stats widget — it additionally implements `HasActions`/`InteractsWithActions`, and its Blade view (`resources/views/filament/widgets/business-overview.blade.php`) is a byte-identical copy of the vendor `stats-overview-widget` view plus `<x-filament-actions::modals />` inside the same root element (Livewire requires exactly one). Each non-Account-Balance `Stat` gets a `wire:click="mountAction('view...')"` extra attribute (CSS: `.zz-business-overview-stat[wire\:click]` in `theme.css` for the pointer cursor/hover, so `BusinessOverviewLayoutTest`'s exact-class assertion didn't need touching). Each drilldown reuses a new generic `resources/views/filament/widgets/partials/dashboard-drilldown-list.blade.php` partial. `ReportService` gained `storefrontPendingOrders()`, `customerPayments()`/`supplierPayments()` (new shared `ledgerQuery()` also de-duplicates `dashboardSummary()`'s own customer/supplier-payment sums), and `comingSoonProducts()`; `CustomerDueAlertService::customers()`/`LowStockAlertService::products()` already existed and are reused as-is.
- **Filament v5's table-filter query-string key is `filters`, not `tableFilters`**: `Filament\Resources\Pages\ListRecords::$tableFilters` is bound `#[Url(as: 'filters')]` — the PHP property name and its URL alias are different strings. A "see all" link built as `OrderResource::getUrl('index', ['tableFilters' => [...]])` (the property name — an easy, silent mistake, since Livewire happily accepts and ignores the extra query param) opens the list completely unfiltered instead of erroring; the correct call is `['filters' => [...]]`. Confirmed live in the browser against Orders/Customers/Products before shipping. `App\Filament\Widgets\BusinessOverview`'s "see all" links all use the correct key; `DashboardDrilldownTest` has a dedicated end-to-end regression test (builds the real modal, extracts its "see all" href, requests it, and asserts the list is actually filtered) specifically so this class of bug fails a test next time. This is unrelated to `CourierMerchantDashboard`'s own documented `tableFilters` workaround noted above (in `[2.2.0]`'s own Technical Notes) — that page isn't a `ListRecords` subclass, so it never had this `#[Url]` binding to use correctly in the first place; its `#[Url]`-bound-property workaround remains the right approach there.
- New migration `add_external_reference_to_orders_table` (nullable `orders.external_reference`, `unique(company_id, external_reference)`) backs the WooCommerce order sync's idempotent upsert; `Order::SOURCE_WOOCOMMERCE` added to `Order::SOURCES` and to `OrdersTable`'s source badge color map (`info`). New `App\Services\WooCommerceOrderSyncService` (`handleOrderEvent()`/`upsertOrder()`/`resolveCustomer()`/`syncItems()`/`mapStatus()`) and `App\Http\Controllers\WooCommerceWebhookController`, new route `POST /webhooks/woocommerce/{company}` (added to `bootstrap/app.php`'s CSRF exemption list, `throttle:120,1`, matching the existing courier/ZiniPay webhook routes). The sync explicitly calls `CompanyContext::set($company)` first, same as `WooCommerceImportService` already does — this route is unauthenticated so `SetCurrentCompany` leaves the context cleared (unscoped) for the whole request otherwise, per `CompanyScope`'s own docblock. `orders.total_amount`/`subtotal`/`due_amount` and stock movements are never set directly — creating `OrderItem` rows through `$order->items()->create()` triggers the same `syncTotalsStockAndCustomerBalance()` recalculation every other order-creation path in this app already goes through. Six new tests in `tests/Feature/WooCommerceOrderWebhookTest.php` (valid webhook, idempotent redelivery, unmatched-SKU line item skipped without failing the order, invalid signature rejected, one company's secret rejected against another company's URL, `order.deleted` cancels rather than hard-deletes).
- New `app/Http/Middleware/SecurityHeaders.php` (appended to the `web` group in `bootstrap/app.php`, after `SetCurrentCompany` — confirmed this doesn't disturb the `SetCurrentCompany`-before-`SubstituteBindings` priority pinning the multi-company isolation docs require, since it only sets response headers and isn't in that priority list). New `app/Support/StorefrontErrorPages.php` is a small, directly-unit-tested classifier (`appliesTo(Request)`/`statusFor(Throwable)`) used by `bootstrap/app.php`'s `withExceptions()` closure — kept out of the closure itself because asserting "did the exception handler intervene" through a real thrown-exception HTTP round trip is fragile under PHPUnit (Laravel's own default exception rendering behaves differently there than in a real request; `config('app.debug')` has to be explicitly forced off in a test alongside `$this->app['env'] = 'production'`, `.env.testing` leaves it on). New `resources/views/components/layouts/error.blade.php` + `resources/views/storefront/errors/{403,404,419,429,500}.blade.php` (moved from `resources/views/errors/` after the bug described below): deliberately brand-neutral (no "ZamZam ERP", no admin links, no company/storefront theme data) since this can render precisely when company/domain resolution itself is what failed. New `tests/Feature/SecurityHardeningTest.php`.
- **Found and fixed a real bug in this same round's own security-hardening work**: the brand-neutral error views above originally lived at `resources/views/errors/{status}.blade.php` — but that is Laravel's own conventional `errors::` view-namespace path (registered app-wide by `Illuminate\Foundation\Exceptions\Handler`'s `RegisterErrorViewPaths`), so simply having a file there made Laravel's *default* exception rendering use it for literally any HTML-rendering exception, completely independent of `StorefrontErrorPages::appliesTo()` — a webhook/admin/API client that doesn't send `Accept: application/json` (WooCommerce's real webhook deliveries, confirmed live) would still get this branded page even though `appliesTo()` correctly said not to intervene, since `bootstrap/app.php`'s closure returning `null` only means "fall through to Laravel's default", and that default itself was contaminated. Fixed by moving the views to `resources/views/storefront/errors/*` (a path Laravel never auto-discovers) and having the closure render that path explicitly — excluded requests now correctly fall all the way through to Laravel's own true default handling. New regression test `SecurityHardeningTest::test_a_403_on_an_excluded_route_never_renders_the_branded_storefront_page` (a real HTTP round trip, not a unit-level `appliesTo()` check, since that's exactly what let this ship in the first place).
- Full `php artisan test` (no `--env` flag) — see UPDATE_NOTES.md for the exact count from this round's final run.
- **Releases are now cut automatically on every push to `main`**, closing the gap that let 25 real commits ship without ever converting `[Unreleased]` into a dated version (the reason `v2.2.0` above needed a manual catch-up in the first place). New `.github/workflows/deploy.yml` job `cut-release` (runs after `tests` passes, `permissions: contents: write`) calls `php artisan release:cut --apply`, which is a no-op whenever `[Unreleased]` has no bullet content and otherwise: converts `[Unreleased]` into a new `## [x.y.z] - <date>` entry, infers the version bump and release type from which sections actually have content (`Added` → Minor Feature Update/bump MINOR; `Security` alone → Security Update; `Changed`/`Fixed` alone → Patch/Fix Update; only `Technical Notes` → Maintenance Update — all four bump PATCH except Minor, matching `docs/release-policy.md`'s version table exactly; `Added` wins over `Security` when both are present, e.g. `v2.2.0`/`v2.1.0` before it, both "Minor Feature Update" despite real security fixes inside them — "Security Update" is reserved for when that's the release's whole point, not merely present in it), then syncs `config/release.php`, `.env.example`, `.env.production.example`, and `docs/deployment.md`'s example env block to the new version/type/date, and commits+pushes that back to `main` with `[skip ci]` (so the bot's own commit doesn't re-trigger the workflow — the next run would find `[Unreleased]` already empty and no-op anyway). A commit can still request `major`/`critical_fix`/`hotfix`/`initial` on purpose by writing an explicit `**Release type:** X` line as the first line under `[Unreleased]` — those four are deliberately never auto-inferred, since a breaking/urgent release should never happen by accident. New `App\Support\ReleaseCutter` (pure, testable: `plan()` takes/returns plain strings, `apply()` is the only place that touches disk) and `php artisan release:cut` (`--apply` required to actually write; without it, always a safe dry run regardless of what `CHANGELOG.md` currently holds). New `tests/Feature/ReleaseCutterTest.php` (16 tests). This only ever updates the repo's own fallback defaults — the production server's actual `APP_VERSION`/`APP_RELEASE_TYPE`/`APP_RELEASE_DATE` env values (Coolify) still override those defaults if set at all; per `docs/release-policy.md` every release should also update those, but since the app's user-facing version displays (Release Notes page, in-app update banner, push notifications) already read `AppRelease::latestPublished()` straight from `CHANGELOG.md` rather than those env vars, **the recommended production setup is to not set `APP_VERSION`/`APP_RELEASE_TYPE`/`APP_RELEASE_DATE` in Coolify at all**, so the repo's auto-bumped fallback always wins and nothing there ever needs a manual touch again.

## [2.2.0] - 2026-08-26

**Release type:** Minor Feature Update

### Added

- Real-time push notifications for business events, delivered to both the Android app and desktop browser tabs (including while a tab is backgrounded, via a new Firebase-powered service worker) — new orders, order status/delivery-status changes, new staff added to a company, a new company created, and a company's info being changed. Every alert is strictly scoped to the owning company's own active admin/staff — one company's activity never reaches another company's dashboard — and further gated by four new selectable permissions (**Notifications: New Orders**, **Order Status Changes**, **New Staff/User Added**, **Company Info Changes**) available wherever roles/permissions are already configured (Settings → User Roles, and each user's Custom Permissions). "New company created" is Super-Admin-only and unconditional, matching how the existing app-update notification already works. Firebase credentials (Web SDK config, Web Push certificate, and the server-side service-account key) are entered by a super admin on a new **Settings → Push Notification Settings** page — encrypted at rest, never `.env`-only — since a real Firebase project has to be created by the owner first.
- Android app: crashes are now saved on-device and automatically uploaded to the server the next time the app launches with connectivity, so a device-only crash the owner hits can be diagnosed from real data instead of guesswork. Super admins can review them under **Settings → Mobile Crash Reports** in the Filament admin panel (exception, message, full stack trace, app/Android version, device model, and when it happened).
- Meta Pixel & Conversions API → Purchase event timing now has a fourth option: **"Only after order status shows Completed"**. The Purchase event stays held (same as the existing "confirmed" option) until the order's status becomes Completed — which happens either through the explicit Completed workflow stage or through Delivered (courier-tracked orders that go Confirmed → Shipping → Delivered without a separate manual "Completed" step also count, since both set the order's status to Completed).
- New **Settings → Integrations** page consolidates every simple-credential third-party integration into one editable form: **AI Assistant** (super-admin only, matching its existing page's restriction), **WooCommerce**, **Payment Gateway** (ZiniPay/PayStation), and **Meta Pixel & CAPI**'s connection fields (Pixel ID + access token — event lists, consent settings, extra Pixels, testing tools, and the event log stay on the existing dedicated Meta CAPI page, linked from its tab) each get their own tab under one **Save changes** button. Solves payment-gateway and WooCommerce credentials being effectively unfindable — they previously lived only inside the Storefront Settings page, several sections below the theme/color pickers, with nothing anywhere else in the panel indicating they existed. **Courier Providers** and **Meta Ads/Ad Manager** are deliberately *not* folded into this form — both manage many provider/ad-account records plus their own dashboards and campaign tools, so merging them would be a regression — they instead get a Connected/Not-configured status card on the same page linking to their existing full areas. Nothing is duplicated: every field reads from and writes straight to the same underlying settings (`companies.settings->ai`, or the company's single `StorefrontSetting` row) the original pages already use, so this page and the original per-integration pages always agree.
- Company Settings → **Shipping Zones** ("Inside Dhaka", "Outside Dhaka", "Suburb areas") are now searchable, multi-select dropdowns — WooCommerce-style — instead of free-text fields, each with a **Select all** shortcut. Inside/Outside list Bangladesh's real districts grouped by division; **Suburb areas** now lists the actual Dhaka-periphery localities used for that delivery zone (Savar, Ashulia, Dhamrai, Keraniganj, Demra, Tongi, Gazipur City area, Narayanganj City area, Kaliakair, Sreepur, Rupganj, Munshiganj Sadar, Narsingdi Sadar, Manikganj Sadar) instead of full districts. Existing saved zones keep working unchanged — the underlying storage format and delivery-fee matching logic are untouched.
- **Storefront Slides** now pick a **Theme + Template** instead of a Company, since each theme/template renders its banner at a different size/ratio — the image upload field shows the exact recommended dimensions for whichever theme/template is selected (sourced from each theme's real view markup, e.g. 1920×640 for the Built-in and Marketplace Pro themes, 1200×900 for Noor Solar Energy) and locks the image editor to that ratio, and the storefront now serves slides matching its own active theme/template (falling back to any legacy slide saved with no theme).
- **Company field removed from every form that doesn't need one, dashboard-wide**: the company selected in the top header now implicitly applies to every create/edit form across the panel — Storefront Slides, Offers, Product Carousels, Storefront Pages, and Storefront Settings no longer show a separate Company selector when one company is already active. The field still appears (and is still required) only while the header is in **All Companies** mode, where there is no implicit company to assume.
- **Shared Stock Pool** (new **Inventory → Shared Stock Pools**, super admin only): for two companies that sell out of the same physical warehouse (e.g. a wholesale company and a retail company under it), a product from each company can now be linked into one pool instead of tracking the same physical stock twice. One product is marked the pool's **Source** (where the real opening/purchase history lives); the other is a linked member. From then on, selling in *either* company updates the same live, always-accurate stock number immediately — no oversell risk and nothing to sync by hand — while each company's own Stock Movement list still gets a matching **Internal Transfer** entry recorded automatically, so per-company accounting history stays complete and traceable. Unlinking a member, or deleting the pool, reverts that product to independent stock tracking. Only available for simple (non-variation) products.
- **Storefront checkout payment methods are now fully dashboard-managed** (new **Storefront → Payment Methods**): checkout used to offer exactly Cash on Delivery, bKash, and Nagad, each hardcoded and gated by its own settings field — adding any other channel (Rocket, Upay, a bank transfer, ...) needed a code change. A company can now add, reorder, rename, and enable/disable any number of manual (send-money/bank-transfer) channels, each with its own account number and instructions. The existing online payment gateway (ZiniPay/PayStation) can also be turned on as a normal checkout choice, letting a customer pay the full order online up front instead of only the previously-existing mandatory pre-order/new-customer delivery advance; the order is created only after that full payment is verified. Single-product Offer pages keep their own separate Cash on Delivery/bKash/Nagad settings for now.
- Reseller management: a new **Resellers** cluster (fully separate from the Customers list, its own top-level sidebar entry) lets staff Approve/Reject reseller applications (bulk actions included), filter by status, and review each reseller's order history and picked-product catalog. Approving a reseller also generates their unique store slug automatically. Once approved, a reseller manages their own mini storefront by logging into their existing storefront account (**Your Store**, under `/account`): they pick which of the company's products to show, and edit their store's URL slug. Their storefront is reachable at `{company-domain}/store/{their-slug}`, built from the same product listing/product/cart/checkout pages as the main storefront, scoped to only the products they picked; orders placed there are attributed to that reseller for future commission/payout reporting (not built yet — only the attribution data is captured now). The entire module is off by default and can only be turned on or off per company by a **super admin**, from that company's edit form in Company Management — existing staff/customers see nothing new until a super admin enables it.
- Android app: renamed to **ZamZam Dashboard**, and added a full-screen loading indicator that now shows the instant a menu item or button is tapped and stays up until the destination page has fully loaded — closing the 2-5 second gap that previously gave no feedback that anything had happened. Only appears inside the app itself; the regular website is unaffected.

### Changed

- Company Management's **Companies** list now follows the top-bar company switcher: a selected company shows only its own row, while **All Companies** shows the full permitted company list.
- Monetary values now begin with a currency symbol throughout the app: Bangladeshi Taka renders as **৳** instead of `BDT`, while other supported currencies use their own familiar symbols. All BDT amount inputs use the same **৳** prefix, and currency-code fields clarify the expected `BDT (৳)` value.
- **Settings → Push Notification Settings** now shows an (i) info icon next to every field, with the exact Firebase Console path and field name to copy (e.g. Project settings → General → Your apps → Web app → `apiKey`, or Project settings → Service accounts → Generate new private key). Helps pin down why push notifications aren't going out — most commonly because "Enable push notifications" was turned on but the actual credential fields underneath were never filled in and saved.

### Fixed

- Storefront Settings now commits native Filament Color Picker values when the picker loses focus, so changing one color and then moving to another field no longer restores a stale black value. The same reliable blur-save behavior also applies to the company dashboard color picker. Shared sticky **Save changes** actions now use Filament's native form-submit flow and explicitly target the page form, restoring save behavior on all affected resource create/edit pages, including Storefront Settings and Company pages.
- **Connect WhatsApp App** (CRM) settings form no longer silently fails to save: the Embedded Signup Configuration ID was wrongly required alongside the Meta App ID/Secret/verify token, even though Meta only issues a Configuration ID after the Tech Provider App Review step is approved — so saving credentials before that (the normal real-world order) failed Livewire validation with no visible error, and the form appeared to reset on reload. Configuration ID is now optional at save time, and validation errors are now shown inline on each field instead of failing silently.
- Storefront cart page's Subtotal and Total rows no longer crash with a 500 error. They referenced an out-of-scope loop variable (`$item`) instead of the cart's actual subtotal, so any customer opening the cart page hit "Undefined variable $item" on every request.
- Storefront checkout page's Subtotal, "Advance payable online now", and weight-based delivery rate note now show the actual cart subtotal, advance-due amount, and configured delivery rates instead of a leftover per-item loop value (a copy of the cart bug above, same root cause).
- Order PDF/invoice download (`/admin/orders/{order}/pdf`) no longer shows the last line item's unit price repeated for Subtotal, Discount, VAT, Total, Paid, Due, and the courier cut-slip's COD amount. Each row now reads its actual order-level field, and orders with no items no longer crash the download with "Undefined variable $item".
- Storefront offer pages (`/offers` and an individual offer's page) no longer 500. They called a non-existent `Offer::finalPrice()`/`Offer::componentsSubtotal()` model method instead of using the already-computed pricing values passed in from `OfferPricingService`.
- **Admin Offers list page (Storefront cluster → Offers) no longer 500s**: the same non-existent-`Offer::finalPrice()` bug fixed on the storefront pages above was still present in the admin list's "Price" column, so opening the list crashed the instant it tried to render any row. Now uses `OfferPricingService::finalPrice()`, same as everywhere else.
- **Offer landing page preview now actually shows a Draft/Archived offer**: previewing an offer (`/storefront/{company}/offers/{slug}`) reused the same "must be Published" lookup as the real public page, so there was no way to review an AI-generated or hand-built landing page before publishing it — the entire point of a preview. Preview now shows any status; the real public page is unchanged (still Published-only).
- Storefront customer account dashboard (`/account`) no longer 500s with a PHP parse error — a stat card's "Total purchased" value had an incomplete expression that never included the actual amount.
- A product's Wholesale pricing table on its storefront page now shows each quantity tier's actual configured price instead of repeating the product's regular selling price on every row.
- Android app: when the WebView briefly fails to reach the server (Wi-Fi/mobile-data switching, a dropped connection), the raw Android "Web page not available / net::ERR_..." error page no longer flashes on screen during each automatic retry — the app's own friendly "Connection Problem" page now shows immediately on the first failure and stays up while retries continue silently underneath it. Also made retries more persistent (up to 6 attempts, 1.5s apart, was 3 attempts 2.5s apart) so a flaky connection gets more chances to recover before the user has to tap "Try Again" themselves.
- Android app: granting the notification permission (or already having granted it on a previous launch) no longer closes the app 1-2 seconds after opening. The app's Push Notifications setup was calling into Firebase before Firebase was actually configured for this build (no `google-services.json` yet — that's an owner-provided Firebase project file, not something invented here), which crashed the whole app the instant it ran. The app now checks with the native side first and skips push setup entirely until a real Firebase config is in place, so opening the app and signing in works normally either way; push notifications themselves will start working with no further code changes once that file is added.
- Android build: fixed a `build-android` CI failure (`cannot find symbol: FirebaseApp`) introduced by the fix above, caused by a Gradle module-visibility gap (the Firebase dependency existed but wasn't visible for compilation in the app's own module).
- **WooCommerce product sync** no longer crashes with "Duplicate entry ... for key `categories.categories_slug_unique`" when a company's synced category name happens to slug the same as another company's existing category (e.g. two companies both getting an "Audio" category). `categories.slug` was still database-wide unique from before multi-company support existed, even though categories are company-owned — it's now unique per company instead, matching how every other company-scoped table in this app works.
- A follow-up full-schema audit (triggered by the above) found and fixed the exact same database-wide-unique-despite-company-scoping bug on two more tables, and a related admin-form-only version of it on five forms:
  - **Products**: `sku` is now unique per company instead of app-wide — two companies stocking the same manufacturer's SKU (or importing from independent WooCommerce sites that happen to reuse a SKU) no longer crashes. `barcode` now has a proper per-company unique constraint in the database to match what the admin form already enforced.
  - **Expense Categories**: `slug` is now unique per company instead of app-wide — every company independently having a "Rent", "Utilities", or "Salary" expense category (extremely common) no longer crashes.
  - **Admin form false rejections**: the Product (SKU, barcode), Purchase's inline "add new product" (SKU, barcode), Expense Category (slug), Courier Provider (slug), and Investor (phone) forms all validated "uniqueness" without scoping the check to the current company, so they could incorrectly reject a value as "already taken" even when another of the owner's companies using the same value is completely legitimate (e.g. adding "Steadfast" as a courier provider to a second company, or the same investor's phone number backing two companies) — all five now scope the check to the current company, matching what the database actually requires.
  - **Related robustness gap**: Customer Payment, Supplier Payment, and Expense numbers were generated as a random (not sequential) code with no retry if two requests ever happened to mint the same one — unlike every other auto-numbered document (orders, purchases, vouchers, quotations, fund transfers), which already retries automatically under this exact collision. All three now use the same safe-under-concurrency retry.
- **Critical: the three company-scoped-uniqueness migrations above (categories/products/expense_categories) failed a real production `php artisan migrate --force` with "Can't DROP 'categories_slug_unique'; check that column/key exists"**, blocking every migration after it (including this round's Storefront Slide and Shared Stock Pool tables). The production `categories`/`products`/`expense_categories` tables' actual live indexes had already drifted from what a fresh install produces, so the original unconditional `dropUnique()`/`dropIndex()` calls assumed indexes that didn't exist. All three migrations now check `Schema::getIndexes()` first and only drop/add what's actually there, so they apply correctly regardless of that drift and are safely re-runnable. A follow-up production run then hit a second, related failure on the same two migrations ("Cannot drop index 'categories_company_lookup_index': needed in a foreign key constraint") — `company_id`'s foreign key needs a supporting index at all times, and the old index was being dropped before its replacement was created. Both migrations now create the replacement `(company_id, slug|sku)` unique index first, then drop the old one, so the foreign key stays covered throughout.
- **Storefront Meta Pixel/CAPI consent banner never actually let tracking through, even after clicking "Accept"**: the banner set its choice via raw `document.cookie` JavaScript, but the storefront's `web` middleware group encrypts/decrypts cookies by default — Laravel silently discards any cookie it can't decrypt, so that plain-text cookie was invisible to the server on every following request. The banner kept reappearing and the Pixel/CAPI stayed off no matter what a customer clicked. "Accept"/"Decline" now submit through a real server route so Laravel sets the cookie through its own encryption pipeline, matching how it's read back.
- **New storefronts no longer require an accept/decline click before Meta measurement works at all**: "Require customer consent" now defaults to **off** for newly created storefront settings — Pixel and Conversions API events fire for every visitor immediately, matching how the Pixel is expected to behave out of the box. Existing storefronts that already have consent required turned on are unaffected; the toggle (Meta Pixel & Conversions API → Consent & Advanced Matching) can still be switched on for stores that specifically need an accept/decline gate for local regulation, and now works correctly when they do (see the bug above).
- **Pixel/CAPI could show "Ready" everywhere and still silently send zero events**: if every checkbox under "Browser events to send" (or every checkbox under a CAPI order-status event list) was ever left unchecked and saved, the storefront stored that as an empty list rather than "unset" — and an empty list was previously treated as "administrator explicitly wants zero events sent," so Meta Pixel Helper would correctly show the Pixel as installed while nothing ever fired, with no warning anywhere in the admin panel. An empty saved list is now treated the same as never having configured one: it falls back to the full default event set, matching what a newly connected Pixel is expected to do.
- **Critical: PayStation online checkout could reject a real customer's payment with "Duplicate invoice number," blocking checkout entirely**. The new-customer weight-based delivery advance (and pre-order advance) used the `StorefrontPayment` row's own database ID as PayStation's `invoice_number` — but PayStation tracks invoice numbers on its own side permanently, independent of this app's database, so any local table reset (a demo refresh, a migration reset, a restore) that restarts the auto-increment sequence would resend a number PayStation had already seen before and reject it as a duplicate, confirmed live in production. The invoice number now always carries a random suffix so it can never collide, regardless of local ID reuse.
- **Header company switcher could silently show the wrong company**: selecting a different company from the top-bar switcher correctly updated the session and redirected, but the browser could answer the following page load from its own cache instead of asking the server again, so the page (including the header's own company label) kept showing whichever company was loaded before the switch until a hard refresh. Every admin panel response is per-user, per-company, and never meant to be reused, so it now always ships `Cache-Control: no-store` (plus `Pragma`/`Expires`) — the storefront's own caching is unaffected, this only applies inside `/admin`.
- **Public quotation link showed wrong totals**: the shareable quotation page's Subtotal, Discount, and Total rows (and each line item's own "Subtotal" column) all echoed a leftover per-item loop variable instead of the quotation's real figures — the same `$item` bug pattern already fixed in the storefront cart/checkout/order-PDF views, just not caught there yet. A customer opening their quotation link now sees the correct line subtotal, quotation subtotal, discount, and total.
- **AI Assistant / Offer "Generate Landing Page with AI" rejected any LLM provider other than Anthropic or OpenAI**: the provider picker was a closed Anthropic/OpenAI choice, and the request always went to OpenAI's own hardcoded endpoint regardless — so pasting a DeepSeek (or any other provider's) API key into the "OpenAI" slot got rejected instantly with OpenAI's own "Incorrect API key provided" error, since that key was never valid at OpenAI in the first place. The AI provider is now fully flexible: pick the wire format (Anthropic's Messages API, or the OpenAI-compatible Chat Completions shape that OpenAI, DeepSeek, Groq, Mistral, OpenRouter, xAI, and a self-hosted Ollama/vLLM all speak), type any provider name you like as a label, and optionally set a base URL to point at that provider's own endpoint — adding a new provider never needs a code change again. Existing companies already configured under the old fixed choice keep working unchanged.

### Security

- **Public quotation links are now unguessable and status-gated**: `/quotation/{number}` had no signature and no status check, so a sequential quotation number (`QTN-20260823-0001`) could be enumerated to read any company's quotation, including drafts never meant to be shared. The link is now a signed URL (same pattern as the existing voucher receipt link) and only resolves for quotations actually marked Sent or Accepted; the admin panel's "Public Link" and "Share on WhatsApp" actions generate the new signed link and only appear for those two statuses.
- **Storefront preview could be used to browse another company's unpublished storefront**: `/storefront/{company:slug}` only checked that *some* staff user was logged in, not that they belonged to that company — any authenticated user could preview any other company's storefront (products, pricing, branding) by guessing its slug. It now requires the signed-in user to actually have access to that company (super admins are unaffected). The same gap existed in the offer-landing-page preview (`/storefront/{company}/offers/{slug}` and its checkout) — fixed the same way.
- **Storefront password login had no per-account lockout**: only a per-IP rate limit (10/min) protected `/account/login`, so a password-guessing attack spread across many IPs against one phone/email was not slowed down at all. A given identifier (phone or email) is now locked out for 60 seconds after 5 failed attempts, regardless of which IP they come from; the counter also advances for identifiers with no matching account, so the lockout itself can't be used to probe which phone/email numbers have accounts.
- **Session cookie could be sent over plain HTTP in production**: `SESSION_SECURE_COOKIE` had no default, so an unset value meant the session cookie was not marked `secure` unless someone remembered to set that env var explicitly. It now defaults to on whenever `APP_URL` is `https://` (the same condition already used elsewhere to force the HTTPS URL scheme) — an explicitly set `SESSION_SECURE_COOKIE` still wins either way.
- **Steadfast courier webhooks could never be authenticated**: the generic webhook verifier assumes every courier signs its payload with HMAC in a custom header, but Steadfast's real webhook panel (Callback URL + a static "Auth Token (Bearer)") sends no signature at all — it just echoes the configured token back in a standard `Authorization: Bearer <token>` header. Steadfast webhooks either always failed verification, or an admin working around that by disabling signature checking left the endpoint accepting any unauthenticated POST (which could forge a delivery-status update for a real consignment). Steadfast now does a plain constant-time comparison against its own configured token instead of inheriting the HMAC check; the other API couriers (Pathao/RedX/E-Courier) are unaffected.
- Closed four race-condition (concurrency) gaps found during a deep audit, all following the same pattern: a stock/fund "is this safe?" check and the write that acts on it happened in two separate, unlocked queries, so two requests arriving at nearly the same moment could both pass the check off stale data.
  - **Stock oversell**: creating/updating/deleting a `StockMovement` now locks its Product row (and variant row, if any) for the whole operation, so the ledger-sum stock check and the insert that follows it can no longer race — two concurrent sales for the last unit of a product can no longer both pass validation and both go through.
  - **Variant stock lost-update**: the same lock also protects `ProductVariant.stock`'s read-modify-write delta, so two concurrent movements against one variant can no longer silently overwrite each other's update.
  - **Purchase overfunding**: approving an inventory-purchase voucher now locks both the voucher row and the Purchase row for the whole approval, so two vouchers funding the same purchase approved near-simultaneously can no longer jointly exceed the purchase total.
  - **Voucher double-processing**: `verify()`, `approve()`, `reject()`, and `cancel()` now all re-fetch and lock the voucher row inside their transaction before checking/changing its status, closing the narrower window where the same voucher could be processed twice from two concurrent requests.

### Technical Notes

- Applied the server-side follow-up deferred in `[1.8.1]`'s Android `net::ERR_SOCKET_NOT_CONNECTED` fix: the Nixpacks-managed app-server Nginx (`nginx.template.conf`) now sets an explicit `keepalive_timeout 120s` (was relying on Nginx's implicit 75s default), giving a kept-alive connection from the Android WebView more headroom across a Wi-Fi/mobile-data switch or a backgrounded app before this Nginx closes it. This only covers the Nixpacks Nginx in front of PHP-FPM inside the app container — the Coolify/Traefik edge reverse proxy sitting in front of it is configured in the Coolify dashboard, outside this repo, and its own idle-timeout is not changed by this entry. If the error still recurs after this change and reinstalling a freshly built APK, that edge timeout is the next thing to check.
- `App\Models\StockMovement::save()`/`delete()` now wrap `parent::save()`/`parent::delete()` in `DB::transaction()` with `lockForUpdate()` on the affected Product/ProductVariant rows (always locked in the same product-then-variant order to avoid deadlocks), acquired before the `saving` hook's ledger-sum validation runs.
- `App\Services\VoucherService::verify()/approve()/reject()/cancel()` now lock the target `Voucher` row (`lockForUpdate()`) inside `DB::transaction()` before re-checking status; `approve()` additionally locks the related `Purchase` row before summing already-approved funding vouchers against it.
- No schema changes to the race-condition fix. Full `php artisan test` run: all previously-passing tests still pass; 13 pre-existing storefront-view failures (unrelated `Undefined variable $item` in `storefront/cart/show.blade.php` and related view bugs, since fixed by the storefront-view fixes above) were confirmed present before these changes too (verified via `git stash`) and were out of scope for this fix.
- `Order::nextOrderNumber()`, `Quotation::nextQuotationNumber()`, and `StorefrontComplaint::nextNumber()` picked "the latest number" with a plain `ORDER BY <column> DESC`, which sorts as text — once a single day's sequence for one company passed 9999, a 5-digit suffix (`...-10000`) would sort *below* `...-9999` and the next number minted would collide with (and get skipped past) an already-used one. All three now sort by string length first, so the numerically-larger suffix always wins regardless of digit count; no behavior change below the 9999/day threshold this was already tested at.
- `SetCurrentCompany` middleware and the `BelongsToCompany` trait's `creating` hook each ran a `Schema::hasTable()`/`Schema::hasColumn()` query on every single request/model-create — a defensive check that only ever matters in the brief pre-migration window of a fresh install, since a column/table that exists never stops existing afterward. Both now cache that check in a static property and stop re-querying once it comes back true, so the extra schema query per request/create only actually happens during that install window.
- Two new migrations ship with this round and must run before their features work: `add_theme_and_template_to_storefront_slides_table` (nullable `theme`/`template` columns on `storefront_slides`) and `create_stock_pools_table` (`stock_pools` table + nullable `products.stock_pool_id`). Run `php artisan migrate` (`--force` in production, per the release policy) before relying on Storefront Slide theme targeting or Shared Stock Pools.
- Two more new migrations ship for the dashboard-managed payment methods feature: `create_storefront_payment_methods_table` (also one-time-backfills every existing company's current Cash on Delivery/bKash/Nagad settings into the new table so upgrading never silently removes a channel a customer could use yesterday) and `add_payment_method_id_to_storefront_payments_table` (nullable FK linking a manual payment back to the exact channel it was made through). Run `php artisan migrate --force` before relying on the new Storefront → Payment Methods resource.
- `StockPool` intentionally has no `BelongsToCompany`/`CompanyScope` — by design it spans exactly two companies' products — and is excluded from `MultiCompanyIsolationTest`'s per-model scope contract with a documented reason, the same deliberate-exception pattern already used for `CustomerBlacklist`.
- `AiSettingsService`'s stored shape (`companies.settings->ai`) changed from a closed `provider` enum to `provider` (free-text label) + `api_format` (`anthropic`|`openai`, the actual wire protocol) + `base_url` (optional endpoint override). A `migrateLegacySettings()` shim in `all()` maps any already-saved `provider: anthropic|openai|deepseek` value forward on read (no migration/backfill needed, no behavior change for existing companies). `AiLlmClient` now takes `$apiFormat`/`$baseUrl` instead of a `$provider` string; `AiReplyService`/`MetaAdsAiAssistantService`'s tool-call message-shape branch (`appendToolExchange`) now switches on `api_format` too. Both live UI surfaces (`AiAssistantSettings` page and the `Integrations` page's AI tab) were updated together since they write through the same `AiSettingsService`.
- Reseller feature: 5 new migrations — `companies.reseller_module_enabled` (boolean, default off), `customers.reseller_slug` (nullable, unique per company), a new `reseller_products` pivot table (`customer_id`, `product_id`, `is_active`), and `reseller_customer_id` added to both `orders` and `storefront_cart_records` (nullable FK to `customers`, `nullOnDelete()`). No destructive changes; all backward compatible and additive. The public reseller storefront (`/store/{resellerSlug}/...`) reuses the existing `ProductIndexController`/`ProductShowController`/`CartController` rather than duplicating them, gated by a new `ResolveResellerFromSlug` middleware that sets a `storefront_reseller` request attribute; each shared controller now reads its own `{slug}` route parameter explicitly via `$request->route('slug')` instead of relying on Laravel's positional (name-blind) controller-parameter resolution, which previously mis-mapped the new `{resellerSlug}` prefix segment into the product/cart `$slug` argument. Full `php artisan test` run: 850/850 passing (829 pre-existing + 21 new, covering the admin dashboard, self-service curation, and public storefront/order-attribution flows).
- Mobile app rename: display name updated in `capacitor.config.json` (`appName`), `android/app/src/main/res/values/strings.xml` (`app_name`, `title_activity_main`), and the Capacitor `webDir` placeholder `mobile-shell/www/index.html`'s `<title>`. `strings.xml` is normally regenerated from `capacitor.config.json` by `npm run mobile:sync`, but was edited directly here since it's checked into git and no Android/Gradle toolchain is available in this environment. `APP_NAME`/`appId` are unrelated and untouched.
- Page-loading indicator: added as a new IIFE inside `AdminPanelProvider`'s existing `PanelsRenderHook::SCRIPTS_AFTER` script (same block as the mobile pull-to-refresh gesture), reusing that block's `inAppWebView` detection (`window.Capacitor` / WebView user-agent sniff) so it is scoped to the Android app only. Listens for Livewire's SPA-navigation lifecycle events — `livewire:navigate` (shows a full-screen overlay) and `livewire:navigated` (hides it) — rather than generic `wire:loading`, so it fires only on actual page changes, not in-place Livewire actions like filters/saves. A 15s safety-net timeout guards against a stuck overlay if a navigation silently fails. No schema/dependency changes; `php artisan test` run: 850/850 passing, 0 regressions.

## [2.1.0] - 2026-08-18

**Release type:** Minor Feature Update

### Added

- Added a selectable **Noor Solar Energy** storefront theme with its approved deep-green/solar-gold palette, Sora/Inter typography, application-led shopping, ERP product and stock signals, project quotation paths, an interactive system builder, and an accessible viewport-lazy Three.js solar-module lab with a WebGL fallback. The theme extends through the shared catalog, product, cart, checkout, tracking, contact, and account surfaces; its design decisions are persisted in `design-system/noor-solar-energy/MASTER.md`.
- New-customer checkout now collects only the calculated delivery charge through the active verified online gateway before creating the customer and order.
- Weight-based delivery pricing supports separate first-kilogram rates for inside and outside Dhaka plus a shared additional-kilogram rate.
- Customer complaints can be submitted with an order/invoice/phone reference and routed to a company-specific Telegram destination while remaining manageable from Filament.
- Registered customers can sign in with a secure, single-use email or SMS OTP alongside the existing password and password-reset flows.
- **Courier Merchant Dashboard** (Courier cluster, first item): a single admin screen showing live balance, delivery/return performance, delivery-margin totals, a clickable **Booking Status Summary** card grid (All/In Progress/Delivered/Partial Delivered/Returned/Cancelled/Failed — each card opens a popup listing that status's own bookings), a **Manage** quick-link card grid right below it, recent consignments, and recent return requests — across **every configured courier**, not Steadfast only. A "Courier" selector defaults to **All Couriers** (combined totals across every provider) and can be scoped to one specific courier at a time; Recent Consignments and the Booking Status Summary popup both show which courier each booking belongs to. Both card grids render with Filament's native stat-card styling, show 5 cards per row on desktop and 2 per row on mobile, and each card carries its own soft color tint. Bookings/Status Logs/Returns/Webhook Logs/Payments are removed from the Courier sidebar menu and surfaced instead as these Manage quick links (also courier-scoped) — staff never need to open any courier's own website.
- Courier bookings can now request a **Steadfast return** directly from the Bookings list/view; requests are recorded locally under a new **Returns** screen for history and filtering.
- A new **Payments** screen shows Steadfast payment/settlement history fetched live from the merchant API, with a **View** action per row opening a structured popup (settlement summary, timeline, and a card per consignment) showing which consignments were cleared under that settlement.
- The Bookings list can now filter by multiple statuses at once and by courier provider.
- Courier booking economics record the configured delivery fee, COD charge, and any previously stored courier cost/margin without exposing a duplicate **Courier Delivery Cost** section on the provider form.
- The Order form's courier reliability check now runs automatically when a customer is selected and displays a per-courier Total/Delivered/Undelivered/Confidence table.
- Courier Providers can be marked **Set as default courier** (one per company); new orders — admin-created or from the storefront — are automatically pre-assigned to it, editable anytime before booking from the order's new **Courier** field.
- The Orders list's five separate per-courier row buttons (Book courier / Book Steadfast / Book Pathao / Book RedX / Book E-Courier) are now one **Book courier** button: a popup shows the courier pre-selected (the order's assigned courier, or the company default), changeable before booking, with the driver-specific fields updating automatically to match.
- Orders list gained a **Book courier** bulk action: select multiple not-yet-booked orders, pick the courier in a popup (pre-filled with the company's default courier, changeable), and book them all in one click. Only couriers that need no extra per-order details (Steadfast, Manual) are offered here — Pathao/RedX/E-Courier require structured fields that can't be safely shared across a batch of different recipients, so use the row-level **Book courier** for those.
- Orders list gained a **Print invoices** bulk action: select any number of orders and print all of them in one click — every selected order's invoice opens in a single new tab and the browser/OS print dialog opens automatically, same "print preview" behaviour as printing one order.
- The order view page gained a **Payments History** ledger (add/edit/delete individual payments — type, method, amount, date, note; the order's Paid/Due totals now stay in sync with this ledger instead of one manually-typed number) and an **Associated Costs** ledger (Purchase / Courier Delivery / COD / Other cost lines), which together feed a new order-level **Profit** figure. Also added: total order weight, product variant, and customer type shown on the order items/details, a click-to-WhatsApp link next to the customer phone, a narrated per-order **Activity** feed (including "viewed"/"printed" events), and **Previous/Next** navigation arrows to page through orders without returning to the list.
- The Products list gained 10 stat cards at the top — **Total SKU, Total Variants, New SKU, Active SKU, Inactive SKU** (each counts every SKU-bearing row, a product plus every one of its variants counts individually, matching how the list itself shows one row per variant), **Total Available Quantity, Total Stock Value, Total Purchase Cost** (real on-hand stock, stock valued at cost price, and money actually spent on received purchases), **Total Shortage** (products at/below their reorder level — click through to a filtered Bulk Update Stock screen), and **Total Damage** (pieces recorded via the new Damage stock movement type below) — 5 cards per row on desktop and 2 per row on mobile, with 10px card padding. Mobile labels/values use 12px/16px while desktop values remain 20px. Currency cards use the `৳` symbol; whole amounts omit `.00`, while meaningful fractional amounts retain up to two decimal places without insignificant trailing zeroes.
- New **Bulk Update Stock** screen (Products list → Bulk Update Stock): every product appears in one searchable/filterable table (by name, SKU, ID, or category), showing current stock and how many pieces short of the reorder level. Each **New Stock** field starts blank; staff may stage one or many values without changing the database, then apply them together with the single Filament **Save changes** header action through the same ledger-safe path as the product edit form. The previous **Upload Stock CSV** and **Stock CSV Sample** header actions were removed from this page. The Total Shortage stat card deep-links here pre-filtered to only short products.
- Stock movements gained a **Damage** type (alongside Opening/Purchase/Sale/Return/Adjustment) for recording stock written off as damaged, with its own required reason and reporting via the Total Damage stat card and the existing Stock Movements filters.
- **Meta Ads Manager, Phase A** (new **Ads** cluster): connect one or more real Meta Ads accounts (App ID/Secret/Access Token/Ad Account ID, encrypted at rest, with a **Test Connection** action) and browse their real Campaigns, then drill into a campaign's Ad Sets, then an ad set's Ads — all in one screen with a breadcrumb, deep-linkable via the URL. Spend/Impressions/Clicks/CTR/CPC stat cards and every row's numbers come straight from Meta's own Insights API via a manual **Sync Data** action (no invented "market analysis" or revenue attribution — this app has no order-to-ad click tracking yet). Viewing/managing accounts requires a new **Marketing/Ads** permission (view/create/update/delete), granted to Manager and Super Admin by default.
- **Meta Ads Manager, Phase B** (write-back): every Campaign/Ad Set/Ad row on the Meta Ads Dashboard gained a **Pause**/**Resume** action (with a confirmation prompt), and Campaigns/Ad Sets gained an inline-editable **Daily Budget** field (Ads have no budget of their own, so it stays read-only there). Every edit writes to Meta first — the local row (and, for budget, the input itself) only updates once Meta actually confirms the change; a rejected or failed call leaves both Meta and the local row exactly as they were and shows a clear error, never a silent or partial update. Creating new campaigns/ad sets/ads and an AI marketing assistant remain separate follow-up phases.
- **Meta Ads Manager, Phase C** (new **New Campaign** action on the Meta Ads Dashboard): create a real Campaign + Ad Set + Ad on Meta in one form — campaign name and daily budget, an audience (age range, gender, Bangladesh-only geo for now), and an ad built from a real **Product** (its image, price, and storefront link auto-fill the headline/primary text/destination, all editable) with a Call To Action. Every object this creates always lands **Paused** on Meta, whatever the form says — use the Phase B Resume action when ready to actually spend. Ad Accounts gained a **Facebook Page ID** field (only needed for this — existing accounts keep working for viewing/syncing/pausing without it), since Meta requires every ad creative to be attached to a real Page.
- **Meta Ads Manager, Phase D** (new **AI Assistant** page in the Ads cluster): a grounded AI agent reasons over this company's own real stock, margin, and trailing 30/60-day sales data (plus any real past ad performance for a product) to recommend which product(s) are worth advertising — one top pick plus up to 2 ranked alternatives, each with real, specific reasoning, per the existing per-company AI provider settings shared with the WhatsApp assistant. Every recommendation is saved as a **draft** the owner reviews, edits, and explicitly sends to **Review & Launch** (which opens Phase C's New Campaign form pre-filled) — the AI never calls Meta and never activates anything itself; every value it proposes is either rejected outright (any product it didn't actually look up) or hard-clamped to a **Min/Max Daily Budget** the owner sets per Ad Account (the assistant is off entirely for an account until a Max Daily Budget is configured — there is no default spending ceiling).
- Each Meta Ad Account credential field (App ID, App Secret, Access Token, Ad Account ID, Facebook Page ID) now has an **(i)** hint icon next to its label — hovering shows exactly where in Meta's own interface (developers.facebook.com or business.facebook.com/settings) to find that value, since a wrong-shaped value pasted into the wrong field (e.g. an App Secret pasted into Access Token) fails Meta's API with an unhelpful "Cannot parse access token" error.
- **Meta Ads Manager, Phase E** (new **Events Manager** page in the Ads cluster): a **Pixel Health** panel shows the selected Pixel's real identity (name, created, last fired, active/unavailable) and its real event volume by type for the chosen window, straight from Meta — shown side by side with (never merged into) this app's own **Event Log** send-attempt counts for the same pixel/window, so "what Meta recorded" and "what we attempted to send" always stay clearly distinguishable. Below that, a real **Audiences** list/manager: sync existing Website and Lookalike Custom Audiences from Meta, create a new **Website Visitors** audience (from a Pixel already configured on Meta CAPI settings, with a retention window and optional URL filter) or a **Lookalike** audience (sourced only from this app's own Website audiences, with an editable target country defaulting to Bangladesh and a 1–20% size), and **Delete** any audience — every write goes to Meta first, the local row only changes once Meta confirms. "Customer List" (bulk hashed-PII-upload) audiences are deliberately not supported. The Pixel itself is still configured on the existing Storefront → Meta CAPI page — no new pixel-credential field was added.
- **PayStation online payment gateway**: Storefront Settings → **Online Payments** gained an **Active gateway** selector (ZiniPay or PayStation), so each company can plug in its own PayStation Merchant ID/Password instead of (or as well as configuring, though only one is active at a time) ZiniPay — each gateway's credential fields only show while that gateway is selected. Checkout, the payment return page, and the payment webhook all route through whichever gateway a payment was actually created with, so switching a company's active gateway never affects a payment already in progress. The Storefront Payments list/filter now recognizes `paystation` as a gateway label.
- CRM Inbox speed: the AI auto-reply agent now also pauses for a rolling 30 seconds whenever a staff member simply has that conversation open in the Inbox (not just after they actually send a reply, which already paused it for 24h) — the window renews for as long as the conversation stays open, so the AI never races an agent who is about to type a reply. The agent's FAQ and delivery-charge lookups are now cached per company for 5 minutes (product price/stock lookups always stay live, matching the existing "never invent price/stock" rule), and it can now request more than one lookup in the same turn instead of always going one round at a time.
- **WhatsApp Business App connection** (CRM → **Connect WhatsApp App**, super admin only): connects the owner's real, already-in-use WhatsApp Business App number into the Inbox via Meta's official Coexistence feature — the phone app keeps working, and up to ~180 days of past 1:1 chat history and contacts sync in automatically, with no risk to the number itself. Save the Meta App ID/Secret/Embedded Signup Configuration ID once, then a "Connect via WhatsApp Business App" button opens Meta's own popup — no manual token pasting for this path. Messages the owner sends from the phone app now also appear in the Inbox timeline (marked "Phone app"), and replies/order links sent from the Inbox reach the phone app the same way they always reached a Cloud-API-only number, since this reuses the existing Meta Cloud API pipeline end to end. Catalog/product message sending is not part of this round — it needs a separate Facebook Commerce Catalog integration this codebase doesn't have yet.
- **Quick Replies** (CRM → **Quick Replies**): staff can save short text snippets and insert them into an Inbox reply with one click (the bookmark icon next to the reply box) — the same idea as the WhatsApp Business App's own Quick Replies feature, now available from the ERP Inbox too.
- **Offers module** (new **Offers** and **Reviews** resources in the Storefront cluster; new public `/offers` funnel): admins build **Single Offer** or **Combo Offer** deals — pick component products (with quantities for combos), price them either as the auto-summed component total or a manual price, then apply an optional percent/flat discount, with a live price preview on the form. Each offer gets its own AI-draftable landing page built from reorderable content blocks (Hero, Product Gallery, Why Buy, How To Buy, FAQ, Testimonials, Custom Text) — a **Generate Landing Page with AI** action drafts Bengali marketing copy from the offer's real product data via the same per-company AI provider already used by the WhatsApp assistant (never inventing a price, stock number, or delivery detail), and every block stays manually editable afterward. The public offer page (`/offers`, tabbed Single/Combo, and `/offers/{slug}`) renders these blocks with a fixed checkout form at the bottom — a standalone one-product-line checkout (name/phone/email/address/quantity/payment method), separate from the existing cart/checkout flow, that explodes a combo into normal per-product `OrderItem` rows (price distributed proportionally, so the existing stock/accounting pipeline needs no changes) and auto-creates a storefront login for a new phone number (the generated password is shown exactly once on the thank-you page, never emailed/SMS'd or placed in a URL). Orders placed this way carry a new `Offer` order source, shown alongside Storefront/CRM/Chat everywhere order source is reported. A new customer-review system lets a logged-in customer leave a star rating + comment from any of their own **completed** orders (`/account/orders/{order}/review`); every review starts **pending** and only appears publicly (including in an offer's Testimonials block) after an admin approves it from the new **Reviews** list (bulk/row Approve, Reject, Delete).

### Fixed

- The Filament login page now resolves its brand name plus light/dark logos from the app's oldest active company before authentication, instead of falling back to legacy global settings when no company context exists. Authenticated pages continue to use the currently selected company, and **All Companies** mode keeps its neutral fallback.
- **Main Company** is no longer recreated by a hard-coded runtime `firstOrCreate` after deletion. Super admins now have confirmed Filament delete actions on company list, view, and edit screens; company-scoped audit metadata and automatic system accounts are removed transactionally with an otherwise-empty company, while database-linked business records safely block deletion with an explanatory notification.
- Selecting the **Noor Solar Energy** storefront theme no longer leaves **Site → Homepage** empty; its existing hero heading, subheading, and call-to-action controls now remain available in the Filament settings form and continue to drive the Noor homepage.
- The Category icon browser no longer clears its search or loses the intended selection when an icon tile is clicked. Selection now stays highlighted inside the modal until the new **Add Icon** action applies it; **Cancel** preserves the previous value, and category edit saves persist the chosen Heroicon correctly.
- Steadfast's payment and return-status API paths were corrected after live testing against a real merchant account surfaced 404s on the originally guessed `/payment` and `/return/{id}` paths; they are now `/payments` and `/get_return_request/{id}`, cross-verified against two independent open-source Steadfast API wrapper packages and confirmed end-to-end (list + drill-down) with a real merchant account's live settlement history.
- Printed/print-to-PDF invoices no longer get clipped on the right edge. The invoice page previously relied on a CSS `@page` margin, which not every print destination (a real printer, "Microsoft Print to PDF", Chrome's own "Save as PDF") honors the same way — some ignore it, some add their own default margin on top of it, and either way the invoice was rendering wider than the printable area. The margin is now built into the invoice's own padding with `@page { margin: 0 }`, so the printed page is always exactly what's shown on screen regardless of print destination.
- Editing an existing Meta Ad Account no longer shows the App Secret/Access Token fields blank and no longer fails to save (or would have wiped the real encrypted credentials) unless they were retyped from scratch every time. App Secret/Access Token still never round-trip to the browser as plaintext — they stay blank on edit and are only overwritten when a new value is actually typed, the same "leave blank to keep the existing value" pattern already used for `ConversationChannel`'s credentials. Also fixed: saving a **Max Daily Budget** (AI Marketing Assistant Guardrails) without also setting a Min Daily Budget no longer fails validation — Laravel's built-in `gte:field` rule cannot treat a blank comparison field as "no floor," so the comparison is now a custom rule that only applies when both are filled.

### Changed

- Monetary values now use one app-wide formatter: whole amounts omit trailing decimal zeroes (`BDT 0`, `BDT 1,625,000`) while meaningful fractions such as `BDT 2,520.5` or `BDT 2,520.25` remain visible across dashboard cards, Filament tables/infolists, storefront, invoices, quotations, receipts, and generated marketing copy.
- Dashboard stat cards no longer show secondary status/description lines beneath their values in **Business Overview**, **Customer Success & Risk**, or **Courier Health**; each native Filament card now contains only its title, primary value, icon, and state color.
- Filament page headers now use one app-wide responsive title scale (20px mobile, 22px tablet, 25px desktop) and 5px spacing above and below the header region across Dashboard, resources, custom settings pages, and Release Notes.
- The CRM Inbox now uses a compact Filament-native layout: channel selection lives in a page-header dropdown, page/section/box spacing is reduced to 5px, the 40px composer keeps an icon-only internal-note action, the thread fills the available viewport with the composer aligned at the bottom, and the empty-chat state is centered while populated conversations remain bottom-aligned and independently scrollable.
- On mobile, the Products page now groups Import CSV, Sample CSV, Bulk Update Stock, Export CSV, and New product into one native Filament action menu at the right of the page title; desktop keeps the existing individual buttons.
- The Products-list stat cards now span the full available header width, matching the main Dashboard layout while preserving the 5-column desktop and 2-column mobile grid.
- The main Dashboard's **Business Overview** now shows 5 stat cards per row on desktop and 2 per row on mobile; its native Filament cards use 10px padding and 20px number/value text for a denser summary.
- Checkout derives the delivery area from the submitted address instead of asking the customer to select an area. The server remains authoritative, configurable Dhaka locality keywords drive detection, and ambiguous addresses default to the outside-Dhaka rate.
- The order thank-you page shows customer/order details and a configurable WhatsApp group CTA.
- Courier-provider credentials now use a text database column, matching Laravel's encrypted-array ciphertext format and allowing providers to be created on MySQL without JSON validation errors.
- Courier Provider create/edit forms now use **Set Delivery Fees** as the only outbound delivery-fee section; the duplicate **Courier Delivery Cost** inputs were removed.
- Courier submenu items now use distinct outline icons: a dashboard panel icon for **Dashboard** and the courier-appropriate truck icon for **Providers**.
- The Courier Dashboard filter now renders as a compact Filament non-native `Select` instead of a full-width browser-native dropdown, and is shown only when the company has more than one active courier provider.
- Printed invoices now use a lighter, more modern system-font stack (Segoe UI / system-ui / Helvetica Neue, falling back to Arial) instead of plain Arial/Helvetica — layout and sizing are unchanged.
- The order edit form's **Paid Amount** field is now read-only once an order exists (still a normal editable field when creating one) — corrections go through the new Payments History ledger on the order view page instead, so the two write paths can't disagree.

### Security

- Login OTP values are stored hashed, expire after 10 minutes, allow at most five failed attempts, have a one-minute resend cooldown, and use non-enumerating responses for unknown accounts.

### Technical Notes

- Added `three` as a production dependency. Vite emits the Noor controller and Three.js as separate lazy chunks, so the 3D library is not downloaded by visitors using other themes and only loads for Noor storefront visitors when the module lab nears the viewport. Focus/touch/reduced-motion safeguards, responsive rendering, palette contrast, theme registration, product-page inheritance, and ERP product output are covered by the focused storefront theme test and production build.
- Added `predis/predis` (pure-PHP Redis client, no server extension to compile) and defaulted `config/database.php`'s `REDIS_CLIENT` to `predis`. Production is now recommended to run `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis` (`.env.production.example` updated; concrete Coolify setup steps added under `docs/deployment.md` → "Redis on Coolify") instead of `sync`/`database`, so the CRM Inbox's AI auto-reply agent (up to 6 sequential LLM calls) runs on a real background worker instead of risking running inline inside the Meta webhook request. `QUEUE_CONNECTION=database` still works without a Redis service — it just misses the FAQ/delivery-charge lookup caching, which requires `CACHE_STORE=redis`. No local dev defaults changed (`.env` stays `sync`/`file`).
- The WhatsApp Coexistence connection reuses the existing Cloud API pipeline: `conversation_channels` gained a `connection_type` column (`cloud_api` default | `coexistence`), and `MetaWebhookController`/`StoreIncomingMessageJob` now also route three Coexistence-only webhook `field` values alongside the standard `messages` field — `smb_app_state_sync` (contacts sync), `history` (queued to a new `ImportConversationHistoryJob`, idempotent, does not bump unread counts or trigger AI replies), and `smb_message_echoes` (phone-app-sent messages, archived with `generated_by = 'phone_app'`). New `EmbeddedSignupSettingsService`/`EmbeddedSignupService` (`app/Services/Meta/`) handle the Embedded Signup code exchange and channel creation; app credentials are stored per company the same encrypted way as the existing AI Assistant settings. New `company_quick_replies` table backs the `QuickReply` model. Both `ConversationChannel` and `QuickReply` are covered by `MultiCompanyIsolationTest`.

## [2.0.1] - 2026-08-11

**Release type:** Maintenance Update

Fixes the CI workflow left stale by the v2.0.0 stack upgrade. No production code changes.

### Technical Notes

- `.github/workflows/deploy.yml`'s `shivammathur/setup-php` step was still pinned to PHP 8.2, which now fails Composer's platform check against `composer.json`'s `^8.4` requirement (`laravel/framework` 13.x itself needs `^8.3`, several `symfony/*` packages need `>=8.4.1`). This only affects the GitHub Actions CI check — it does not gate or affect Coolify's own deploy, which listens to the push webhook directly and already deployed v2.0.0 successfully before this was caught. Bumped to `8.4` to match.

## [2.0.0] - 2026-08-11

**Release type:** Major Version Update

Completes the Stack Upgrade Plan and clears every remaining Step 6 pre-production-deploy requirement: PHP raised to 8.4, Laravel to 13, Livewire to 4, and Filament to 5, plus the mandatory production MySQL authentication fix applied and verified ahead of this deploy. No user-facing behavior changes — this release is the infrastructure milestone that lets production run the same stack already verified on staging.

### Technical Notes

- `laravel/framework` 12.64.0 → **13.23.0** (with `laravel/tinker` 2.11.1 → 3.0.2 and `phpunit/phpunit` ^11.5 → ^12.0 as required companions); `filament/filament` and all `filament/*` sub-packages 4.12.5 → **5.7.5** with `livewire/livewire` 3.8.3 → **4.3.4** pulled in automatically. `composer.json`'s `"php"` constraint is `^8.4`. Full breaking-change review for both Laravel 13 and Livewire 4/Filament 5 found nothing this codebase needed to change beyond `AdminPanelProvider.php`'s CSRF middleware rename; `php artisan filament:upgrade` handled the rest mechanically.
- `nixpacks.toml`'s `NIXPACKS_NODE_VERSION` raised `"20"` → `"22"` so Nixpacks' pinned nodejs package (20.18.1) no longer falls short of `vite:^7.3.5`'s `>=20.19.0` floor; confirmed on the following staging deploy by the Vite engine warning disappearing entirely.
- Final full `php artisan test` on PHP 8.4.24, run immediately before this production push: **608 passed, 3,547 assertions, zero failures.** `npm run build` is clean with no console errors (confirmed via the staging deploy logs after the Node 22 fix).
- **Production pre-deploy critical fix completed:** production MySQL 8.4 defaults new/altered users to `caching_sha2_password`, which Nixpacks' PHP 8.4 `mysqlnd` build cannot authenticate against (no compiled RSA/OpenSSL support). Applied the same fix already verified on staging directly to the production database resource (`mysql-database-iom7u0wab3i2ucilif2kl1ms`, the container actually referenced by the app's `DB_HOST`): mounted `/etc/mysql/conf.d/native-password.cnf` with `mysql_native_password=ON`, restarted the database resource, then `ALTER USER ... IDENTIFIED WITH mysql_native_password` on the production app's database user (password unchanged) and `FLUSH PRIVILEGES`. Verified via the real Laravel application (`DB::connection()->getPdo()` from inside the running production app container), not the native `mysql` CLI, per the Step 6 requirement.
- Took a full production database backup (`mysqldump --no-tablespaces --single-transaction`) immediately before this deploy, stored on the production host.
- Owner ran staging with this full stack for several days across storefront and admin usage with no runtime errors before approving this production push.

## [1.23.0] - 2026-08-03

**Release type:** Minor Feature Update

Introduces a professional, fully configurable storefront design system with multiple homepage themes, a complete customer account experience, responsive commerce refinements, and richer category presentation.

### Added

- **Site Theme system:** the existing storefront is now the **Built-in Theme**, joined by **Marketplace Pro** with Hero-driven, Campaign-driven, and Compact & dense homepage templates. Each theme exposes only the feature settings relevant to its layout while sharing the existing catalog, cart, checkout, tracking, and account functionality.
- **Professional theme controls:** Storefront Settings now provides clearly separated brand, light-mode, and dark-mode palettes; automatic or explicit foreground contrast; typography presets and individual font controls; and modern appearance settings for content width, gutters, radius, card depth, hover feedback, and motion.
- **Complete customer account area:** customers can access an account overview, profile and password settings, order history, reorder actions, account activity, order tracking, reseller status, and logout. The header profile dropdown links directly to these destinations and contains the storefront light/dark switch.
- **Category image and icon support:** category create/edit forms and the Product form's inline Create category action can assign a compressed square image or a curated storefront icon. Storefront category tiles fall back from image to icon to category initial.
- **Visual Filament icon browser:** clicking the Category icon field opens a searchable, scrollable modal containing every solid and outline Heroicon bundled with the installed Filament version. Selecting a tile applies it immediately, while existing category icons remain backward compatible.

### Changed

- Storefront pages now use a denser responsive commerce layout with compact Amazon-inspired typography, spacing, cards, headings, controls, and account screens while retaining accessible 44px mobile touch targets.
- The responsive header keeps navigation below the logo/search row on desktop, moves account and theme controls into one compact profile menu, uses a mobile drawer below the desktop breakpoint, and hides the duplicate header cart on phones.
- Homepage banners preserve the complete uploaded image at full available width on desktop and mobile, adapting their height to each image's intrinsic aspect ratio instead of cropping it into a fixed stage.
- Product detail galleries are capped to a compact premium size and add pointer-position hover zoom on supported desktop devices; thumbnails and purchasing controls remain mobile responsive.
- Theme-colored buttons, badges, selected states, CTA areas, and navigation now resolve readable text from the configured foreground mode. Dark-mode cards use opaque matching surfaces so decorative lines and page effects do not bleed through them.
- The **Inventory** sidebar module exposes Products, Categories, and Stock Movement as direct submenu destinations. The desktop sidebar now rests in an icon-only collapsed state, expands on pointer hover or keyboard focus, and collapses when interaction leaves without changing Filament's mobile drawer.

### Technical Notes

- New storefront settings migrations add customer activity storage, theme foreground mode, palette and typography tokens, advanced appearance controls, theme/template selection, category icons, and sufficient icon-key length for every bundled Filament Heroicon. Deploy with `php artisan migrate --force` before serving the updated storefront.
- Theme registration and safe template resolution live in `App\Support\StorefrontThemeRegistry`; category icon validation lives in `App\Support\StorefrontCategoryIcons`. Shared CSS variables from `StorefrontSetting` drive palette, typography, spacing, radius, elevation, and motion consistently across storefront pages.
- The category icon picker is a Filament field/modal built from the installed `Filament\Support\Icons\Heroicon` enum, so the catalog tracks the framework icon library without maintaining a separate hardcoded list. A delegated panel script now filters the rendered icon tiles directly on typing, native search-clear, or Enter and prevents Enter from submitting the category form. Sidebar behavior uses Filament's native collapsible desktop state with a progressive hover/focus controller.
- Added storefront theme and category-media feature coverage. The focused theme/category/image-precompression suite passes with 9 tests and 55 assertions, and the production Vite build succeeds.

## [1.22.1] - 2026-08-01

**Release type:** Maintenance Update

Replaces the third-party `shahariar-ahmad/courier-fraud-checker-bd` package (Part 1 of the Stack Upgrade Plan) with an in-house HTTP client for the same Pathao/Steadfast/RedX fraud-check lookups, and raises the minimum PHP version to 8.4 (Part 2). No user-facing behavior changes — these are infrastructure steps ahead of the planned Laravel 13/Filament 5 upgrade.

### Technical Notes

- New `App\Services\CourierFraud\CourierFraudClient` interface plus `PathaoFraudClient`, `SteadfastFraudClient`, and `RedxFraudClient` — each replicates its corresponding package class's HTTP flow (Pathao merchant login + success-ratio lookup; Steadfast portal CSRF/cookie/login/fetch/logout; RedX token login with per-merchant cached access token) using `Illuminate\Support\Facades\Http` directly, with credentials passed in per call instead of read from static package config.
- `App\Services\ExternalCourierFraudService` now dispatches to the new clients (`DRIVER_CLIENT_MAP`) instead of the package's `PathaoService`/`SteadfastService`/`RedxService`; behavior (24h cache, graceful per-courier failure, audit logging) is unchanged, confirmed by the existing `ExternalCourierFraudCheckTest` suite passing unmodified against the new clients.
- Removed `shahariar-ahmad/courier-fraud-checker-bd` via `composer remove`; `bootstrap/cache/packages.php`/`services.php` regenerated via `composer install` to clear the stale manifest.
- New `tests/Unit/Services/CourierFraud/{Pathao,Steadfast,Redx}FraudClientTest.php` (14 tests) cover success and failure paths for each client with `Http::fake()`.
- `composer.json`'s `"php"` constraint raised `^8.2` → `^8.4` (longest security-support window; Laravel 13 requires PHP 8.3+ minimum). `docs/deployment.md`'s server requirement updated to match. `nixpacks.toml` needs no separate PHP-version pin — Nixpacks' PHP provider auto-detects the version from `composer.json`. Static scan of `app/` for the PHP 8.4 implicit-nullable-parameter deprecation (a typed scalar/class param defaulting to `null` without an explicit `?` prefix) found none.
- The local dev machine's PHP was then upgraded from 8.3.30 to the official PHP 8.4.24 (TS, VS17, x64) Windows build (windows.php.net, sha256-verified) so this could actually be verified locally instead of only on staging: `composer update` under real PHP 8.4 succeeded (`composer.lock` now fully refreshed — Laravel 12.62.0 → 12.64.0, Filament 4.11.7 → 4.12.5, Livewire 3.8.1 → 3.8.3, and other patch/minor bumps; nothing pinned to Laravel 13/Filament 5 yet, per plan ordering), and the full `php artisan test` suite passes under PHP 8.4.24 (555 passed, 2,974 assertions).
- **Fixed a pre-existing bug in `nginx.template.conf`** (found via a fresh Coolify staging build, which crash-looped nginx with `duplicate location "/"`): the Laravel (`IS_LARAVEL`) and PHP-fallback (`NIXPACKS_PHP_FALLBACK_PATH`) `location /` blocks were two independent `$if` blocks instead of one, so both rendered at once. Not caused by the PHP 8.4 change (would have broken any fresh build of this repo state) — first surfaced now because this was the first from-scratch build since the file was added.
- **That first attempt (nesting a second `$if` inside the first's `else`) was itself broken and shipped a second crash-looping build.** Nixpacks' renderer (`scripts/config/template.mjs`) resolves `$if(COND) (...) else (...)` with a single-pass, non-greedy regex that cannot parse nested parentheses — it stops each capture at the first literal `)`, so the nested block's text looked correctly nested but rendered into a broken `nginx.conf` with stray unmatched parentheses, and nginx exited immediately on container start. Verified by re-implementing Nixpacks' exact regex-based algorithm and rendering the real template file (both directly in Node, matching Nixpacks' own script, and as a permanent PHP test double). Fixed properly this time by dropping the conditional entirely: this repo's `nixpacks.toml` and Nixpacks' own auto-detection mean `IS_LARAVEL` is always `"yes"` here, so the non-Laravel fallback branch is dead code and is simply omitted rather than conditioned. New regression test `LivewireTemporaryUploadConfigurationTest::test_nixpacks_template_renders_without_leftover_conditional_syntax` actually renders the template (via a faithful PHP port of Nixpacks' algorithm) instead of only pattern-matching the raw source text, which is what let the first, broken fix pass unnoticed.
- **Confirmed staging fully live under PHP 8.4.** MySQL 8.4's `caching_sha2_password` default is incompatible with Nixpacks' PHP 8.4 `mysqlnd` build (no compiled OpenSSL/RSA support — a PHP build limitation, not fixable in code); switched staging's MySQL user to `mysql_native_password` (enabled via a mounted `/etc/mysql/conf.d/native-password.cnf`, server restart, then `ALTER USER`). **Production's database has the same incompatibility** (confirmed read-only) and will need the identical fix applied before production is ever redeployed on PHP 8.4 — tracked as a mandatory pre-deploy step in `03_STACK_UPGRADE_PLAN.md`'s Step 6 checklist. `php artisan migrate --force` and `php artisan db:seed --force` (with `ADMIN_*` env vars) then completed cleanly on the previously-empty staging database, and the Filament admin panel was reached with a live login.
- Part 3 of the Stack Upgrade Plan: `laravel/framework` 12.64.0 → **13.23.0**, with the required `laravel/tinker` 2.11.1 → 3.0.2 companion bump (2.x only supports `illuminate/*` up to ^12) and `phpunit/phpunit` ^11.5 → ^12.0. `app/Providers/Filament/AdminPanelProvider.php`'s `VerifyCsrfToken::class` updated to the renamed `PreventRequestForgery::class` (Laravel 13 CSRF middleware rename; old name still works as a deprecated alias). Checked every item in Laravel's 13.x upgrade guide against this codebase — no `Route::domain()`/`->upsert()` usage, cache/session key-prefix fallbacks are already app-defined (unaffected by the changed skeleton default), and the new `cache.serializable_classes` hardening key is absent from `config/cache.php` so behavior is unchanged (confirmed by reading the `CacheManager`/`FileStore` source directly) — left as an optional future hardening item since adopting it would require allow-listing `App\Models\StorefrontSlide` and other Eloquent objects currently cached as-is. Full `php artisan test` under PHP 8.4.24: 556 passed, 2,981 assertions, no regressions; `MultiCompanyIsolationTest` reconfirmed separately; `npm run build` succeeds.
- Parts 4+5 of the Stack Upgrade Plan (done together, since Filament v5 pulls Livewire v4 in automatically): `filament/filament` and all `filament/*` sub-packages 4.12.5 → **5.7.5**, `livewire/livewire` 3.8.3 → **4.3.4**. No code changes were needed — `filament:upgrade` handled the mechanical changes, and Livewire's 3→4 breaking-change list (route registration, `wire:model` modifier changes, config key renames, self-closing component tags) doesn't touch anything this codebase actually uses. Full `php artisan test`: 556 passed, 2,981 assertions, no regressions (same count as the Laravel 13 step). Manually browser-smoke-tested every area the plan flagged as highest-risk — dashboard widgets, Products list/create (FileUpload rendering), Inbox, Cloud Storage Settings (`wire:model` text input + toggle switch), and a Quotation's Repeater "Add item" flow — all working, zero console/server errors.
- `nixpacks.toml`'s `NIXPACKS_NODE_VERSION` raised `"20"` → `"22"`: staging's first post-Filament-5 deploy build succeeded but logged a Vite warning (`package.json`'s `vite:^7.3.5` and its own `engines.node` require `>=20.19.0`; Nixpacks' `nodejs_20` package resolves to 20.18.1, just under that floor). Nixpacks only supports major-version selection, not patch pins, so bumping to the `22` major line is the fix.

## [1.22.0] - 2026-07-23

**Release type:** Minor Feature Update

Adds native Android update notifications and makes the user-controlled client upgrade boundary explicit.

### Added

- **Android update push notifications:** registered Capacitor devices can receive one Firebase Cloud Messaging notification per deployment, including while the app is in the background. Opening the notification only reveals the pending update; it never acknowledges or activates it.
- **Installed versus available release state:** Release Notes now identifies the user's acknowledged version separately from the newly deployed version, so an available release is not presented as already installed before Upgrade App is confirmed.

### Changed

- When an update is pending, same-origin Filament SPA navigation is held on the already-loaded screen and opens the Upgrade App prompt instead of fetching a newer page. The authenticated Upgrade App POST remains the only path that acknowledges the deployment and performs a cache-cleared reload.
- Database notification delivery and native push delivery are tracked independently, with per-device/per-deployment deduplication and invalid-token retirement.
- Admin image uploads now resize eligible JPEG/PNG files in the browser before the Livewire transfer (1600px for standard media, 800px for compact media), then receive the existing final server-side WebP optimization. SVG, GIF, and WebP uploads stay on the compatibility-safe server path.

### Technical Notes

- Native Android push requires the Capacitor Push Notifications plugin, a Firebase Android app registered as `com.zamzamint.erp`, `android/app/google-services.json` injected at build time, and server-side Firebase HTTP v1 service-account credentials. Secrets are never committed.
- Deployments must run `php artisan migrate --force` and `php artisan release:notify-deploy` after the new container is healthy; the five-minute scheduler remains a recovery path.
- A single replaced Coolify container cannot retain an executable old PHP/Blade backend per user. This release preserves the loaded client screen and prevents SPA navigation across the detected boundary; strict continued use of the complete old backend requires immutable blue/green releases with sticky per-installation routing and backward-compatible shared storage/database migrations.

## [1.21.0] - 2026-07-23

**Release type:** Minor Feature Update

Adds a user-controlled application upgrade flow, reliable in-app update notifications, and personal profile management from the header avatar menu.

### Added

- **Upgrade App in the profile menu:** a highlighted action appears immediately above Sign out only when a newer application build is available. The currently open app does not reload automatically; the user chooses when to upgrade and receives an explicit warning to save unfinished work first.
- **App-update notifications:** the existing Filament notification bell now receives persistent update alerts with a direct Release Notes action. Desktop and mobile unread counts refresh every 15 seconds, and each user gets at most one alert per application build.
- **Profile Settings:** the avatar menu now links to Filament's native profile page, where the signed-in user can safely update their own name, email, and password.

### Changed

- Storefront domain ownership is now unambiguous: Company create/edit no longer exposes writable domain or verification controls, while Site → Settings remains the sole editor. Changing a hostname resets its old verification status, related writes are transactional, and Company/Site Settings save actions now remain in the sticky Filament page header.
- Cloudflare R2 connection tests now validate and stage the values currently entered in the Cloud Storage form instead of silently testing only an older saved copy. Blank encrypted-secret fields retain the stored key, test clicks cannot enable R2, and missing bucket/domain values are identified on their exact fields.
- Application updates are detected from a build identity instead of only the top `CHANGELOG` version, so backend-only changes and multiple builds under the same human-readable version are no longer missed.
- Save-result refresh and Android pull-to-refresh check the loaded build first. If a newer build exists, they reveal the Upgrade App action instead of silently replacing the open app.

### Technical Notes

- Migration `2026_07_23_000000_create_app_update_tracking` adds each user's acknowledged deployment ID and the unique `app_update_deliveries` ledger. Deploy with `php artisan migrate --force`.
- `npm run build` now creates `public/build/deployment.json` from the source-tree hash, Vite manifest hash, build time, and Git/platform commit when available. The combined artifact identity changes for same-commit source/asset changes, and runtime readiness fails closed when the built manifest is missing or mismatched. `/health/version` exposes the no-cache identity for the admin poller.
- Rolling old/new containers are ordered by build time on both server and client. Older nodes cannot replace the newest notification baseline, trigger a downgrade prompt, or acknowledge a different deployment than the one the user confirmed.
- `AppUpdateService` writes Filament-format database notifications synchronously, so app-update delivery does not depend on a queue worker. Request-time discovery delivers only to the current user, while the scheduled `release:notify-deploy` command fills any missing users without duplicating existing deliveries.
- The deferred-upgrade contract retains the currently loaded browser/Capacitor frontend until consent. The PHP backend changes at server deployment time; retaining an entire old backend would require separate blue/green infrastructure and backward-compatible migrations.

## [1.20.0] - 2026-07-18

**Release type:** Minor Feature Update

Merges the two overlapping homepage-banner systems into one. Previously "Hero Slides" (full-width scheduled slider) and the Storefront Settings "Banner images" repeaters (fallback side-card with product links) lived side by side with different feature sets. Hero Slides is now the single place to manage homepage banners.

### Added

- **Product link on hero slides:** each slide can optionally link to a product (the old banner-only feature) — clicking the slide image opens that product's page. An explicit CTA URL still wins over the product link.
- **WooCommerce sync now imports variations and full product data:** variable products pull all their variations (option combinations like Size/Color with per-variation SKU, regular/sale price, active status, order, and image) into ERP product variations — matched by variation SKU (or option set) on re-sync so nothing duplicates. The import also now takes the full product description instead of only the short one, downloads the remaining photos into the product gallery (first sync only, so admin-curated galleries stay untouched), and fills the Brand field from a "Brand" attribute when the old site has one. Stock is still never imported (ERP stock comes from stock movements only).
- **Marketplace-style homepage (MoveOn-inspired), mobile-first:** round category icons in a swipeable row right under the hero, product cards in a dense 2-column grid on phones (4 columns desktop), a new "Explore more products" grid (homepage now shows up to 23 products instead of 12), the how-to-order steps moved to the bottom, and a brand-colored "Ready to order?" call-to-action band above the footer.
- **"How to order" steps redesigned and animated:** the four ordering steps are now cards with the same 3D gradient icon badges (search, cart, clipboard-check, package). Number chips were dropped in favour of a looping progress animation — each step lifts and its badge pulses in sequence, and on desktop a gradient progress line with a glowing runner dot travels from step to step, so a visitor instantly reads the order flow. The animation starts only when the section scrolls into view and respects reduced-motion settings.
- **Admin-managed header & footer navigation menus:** Storefront Settings has a new "Navigation Menus" section with two drag-to-reorder menu builders (header and footer). Each item has a label plus a link type — Shop all products, a specific Category, a Content page, Track order, My account, Become a reseller, or a Custom URL — with an optional "open in new tab" toggle. The header menu shows in the desktop navigation bar and in a new mobile hamburger drawer (which also lists categories); the footer menu becomes a "Quick links" column. When left empty, the storefront keeps its automatic defaults (Shop all / Track order / Account, and the published-pages footer list). Broken items (deleted category/page) are skipped automatically.
- **Premium trust badges:** the delivery/returns/payment reassurance lines are now three cards with 3D-style gradient icon badges (glossy highlight + colored glow shadow — blue truck, green returns, amber cash) instead of plain checkmark text.
- **Mobile hero banner fix:** slides with a portrait mobile image now get a taller 3:4 stage on phones so the image shows uncropped (previously the wide 16:9 crop cut off portrait banners); slides without a mobile image keep the wide crop.
- **One-time data migration:** existing Storefront Settings banner images (desktop + mobile, with their product tags) are automatically converted into hero slides on deploy — for companies that had no slides yet (companies already on slides never displayed the banner fallback, so their stale banners are not resurrected).

- **Storefront primary-color consistency fix:** header/footer WhatsApp buttons, Buy Now, Add/Update cart, checkout, track-order, reseller, and category-filter buttons now use the company's configured brand color (Storefront Settings → Branding) as a solid fill instead of a hardcoded black button that only revealed the brand color on hover (or swapped to plain white in dark mode).
- **Redesigned printable invoice (matching the Zamzam International layout):** centered company name with hotline, scannable Code 128 barcode of the invoice number, Bill To block, delivery partner (from the latest courier booking), item table with SL / product image / item name / weight / unit price / qty / amount columns, Sub Total → Grand Total → black Due Amount bar, contact block with Facebook/email/website/address, footer contact strip (hotline · Facebook page · WhatsApp), thank-you message, and a scissor cut-slip for the courier parcel (mini header, Bill To, barcode, black due-amount chip).
- **Invoice page is now a true A4 sheet with the footer pinned to the bottom:** the printable page is sized to exactly 210mm × 297mm (both on screen and when printed), and the contact strip / thank-you note / courier cut-slip footer now always sits flush against the bottom of the page instead of floating right under the totals on short invoices. The item table header still repeats automatically on every printed page for orders long enough to span more than one page.
- **Invoice Settings in Settings → ERP Settings:** header hotline, footer hotline, Facebook page URL/label, WhatsApp number, website, thank-you message, and toggles for the product-image column, weight column, barcode, and cut-slip — all per company, nothing hardcoded.
- **Product weight (kg):** new optional field on products (Product form), shown in the invoice weight column and imported automatically from WooCommerce during sync.
- **New storefront Contact Us page** (professional reference design): a brand-colored hero, four contact method cards (Email, Chat on WhatsApp, Help Center, Call Us — each auto-hidden when its data isn't configured), an "Our Location" section (company address + a "Find on Map" link), a FAQ accordion pulled from the existing FAQ list (Settings → CRM → FAQs), and a "Still Have Questions?" call-to-action. Two new admin fields — Support email and Support hours — added to Storefront Settings; everything else (WhatsApp/phone/address/FAQs) reuses settings that already existed. Linked from the footer and reachable at `/contact`.
- **Professional redesign of admin-authored storefront pages** (About, Terms & Conditions, Privacy Policy, Return & Refund Policy, Advance Payment Policy, and any other page in Settings → Storefront → Pages): breadcrumb, optional cover image, "Last updated" date, and a "Still have questions? Contact us" box at the end. The page content editor is now a rich-text editor (headings, bold, lists, links, tables) instead of a plain textbox — existing plain-text pages keep rendering exactly as before.
- **Storefront accessibility & UX audit fixes** (Vercel Web Interface Guidelines review): the homepage now always has exactly one `<h1>` (previously missing when hero slides were configured); the header's "Categories" menu opens on click/keyboard instead of hover-only, so it's reachable without a mouse; a skip-to-content link, `color-scheme`/`theme-color` meta tags, and an automatic `<link rel="preconnect">` for the image storage origin were added; fixed-position bottom bars (mobile nav, sticky "Buy now") now respect the iPhone home-indicator safe area; checkout/contact/reseller/account phone fields use `type="tel"` with `autocomplete`; the checkout submit button now disables itself with a "Placing order…" state to prevent double-submits, warns before leaving with unsaved input, and its live total announces updates to screen readers; cart's "Remove" button now asks for confirmation; quantity inputs on the product page no longer lose their focus ring; and a few other small polish items (touch targets, image loading hints, curly quotes).
- **Storefront search bar and customer accounts:** the header now has a real product search box (name/SKU, works from any page, keeps the search term through sort/pagination) plus a customer profile icon with a dropdown menu. Customers can create an account (name, phone, optional email/address, password) or log in with either their phone number or email. Production "My Orders" and reorder actions require the authenticated owning customer; public guests can track one order with order number + checkout phone, while signed success/tracking links avoid exposing phone numbers in URLs. When accounts are disabled, account-history links fall back to manual tracking instead of a phone-only history list. Logged-in customers get immediate order history, profile/password management, reseller status, and checkout-prefilled contact/address data. The "Forgot password?" flow texts a 6-digit reset code through the store's existing SMS gateway. The "Official storefront - live catalog, direct ordering" announcement strip above the header has been removed. Login/register controls retain keyboard focus management and clear password requirements.

### Removed

- The "Banner images (desktop/mobile)" repeaters in Storefront Settings → Branding (replaced by a pointer note to Hero Slides), the `banner_images`/`banner_images_mobile` columns, and the fallback banner carousel on the homepage (with no slides, the hero now falls back to the first product photo as before).

### Technical Notes

- Migration `2026_07_18_120000_merge_storefront_banners_into_slides` adds `storefront_slides.product_id` (FK, null on product delete), copies banner data into slides, and drops the two JSON columns. Deploy needs `php artisan migrate`.
- `WooCommerceImportService` gains `importVariations()`/`importVariation()` (paginated `/products/{id}/variations`, upsert by SKU → options signature fallback, variant images downloaded once); parent `has_variants`/`variant_attributes` set from the Woo attribute definitions. New variable-product test in `WooCommerceImportTest`. Re-running "Sync WooCommerce" on an already-synced store backfills descriptions/galleries/variations.
- New dependency-free `App\Support\Code128` renders the invoice barcode as inline SVG (ISO/IEC 15417 Code 128-B with checksum). Invoice settings live in `companies.settings['invoice']` via `CompanySettingsService::invoice()/saveInvoice()`. Migration `2026_07_19_120000_add_weight_kg_to_products` adds `products.weight_kg`. New `InvoiceDesignTest`; `CompanySettingsTest` invoice assertions updated to the new markup.
- `resources/views/orders/print.blade.php`: `.invoice` is a flex column sized with `--page-width`/`--page-height` CSS variables (210mm/297mm, matching `@page { size: A4 }`); the contact strip, thank-you note, and cut-slip are now grouped in a `.invoice-footer` wrapper with `margin-top: auto`, which pushes the whole group flush to the bottom padding of the page. Verified via computed `getBoundingClientRect()` in-browser: on a normal (single-page) invoice the footer's bottom edge lands exactly at the page's bottom padding edge. Known limitation (not fixable in pure CSS in any browser's print engine, no `running()`/paged-media support in Chrome): if an order has enough items to spill onto a second printed page, the footer follows immediately after the last row rather than being pinned to the second page's bottom — same as before this change, and not the common case for this app's orders.
- Installed the `vercel-labs/agent-skills@web-design-guidelines` skill and ran it against all 13 `resources/views/storefront/**/*.blade.php` files, then fixed every finding: `layout.blade.php` (skip link, keyboard-operable Categories dropdown via Alpine `x-data`/`aria-expanded` instead of CSS `:hover`, `color-scheme`/`theme-color` meta, conditional `<link rel="preconnect">` derived from `Storage::disk('public')->url()`'s host, safe-area padding on the fixed mobile nav, icon-only call button `aria-label`, shared `data-confirm` submit-guard script); `home.blade.php` (sr-only `<h1>` on the hero-slides branch since the slide heading is only an `<h2>`, `text-balance` on both hero headings); `products/show.blade.php` (`fetchpriority="high"` on the main gallery image, `focus:ring` restored on the two quantity inputs that had `outline-none` with no replacement, `[touch-action:manipulation]` on stepper buttons, safe-area padding on the sticky "Buy now" bar); `partials/product-card.blade.php` (descriptive `aria-label` on the icon-only quick-add button); `cart/show.blade.php` (`data-confirm` on the Remove form, stepper touch-action); `checkout/show.blade.php` (`autocomplete`/`type="tel"`/`spellcheck` on the delivery form fields, submit-button disable + "Placing order…" state, `beforeunload` guard while the form is dirty, `aria-live="polite"` on the Alpine-computed total, `truncate`/`min-w-0` on the order-summary item name); `account/orders.blade.php`/`reseller/apply.blade.php`/`track/show.blade.php` (`type="tel"` + `autocomplete` on phone fields); `contact/show.blade.php` (`scroll-mt-24` on the `#faq` anchor so it doesn't land under the sticky header, curly apostrophes); `pages/show.blade.php` and `track/show.blade.php` (curly apostrophes). Full `php artisan test` — 327 passed (1421 assertions); `npm run build` succeeds; browser-verified the Categories dropdown (click + `aria-expanded` toggle), single `<h1>` count, and focus-ring/fetchpriority attributes directly via computed DOM state.
- Primary-color fix touches `layout.blade.php`, `home.blade.php`, `products/index.blade.php`, `products/show.blade.php`, `cart/show.blade.php`, `checkout/show.blade.php`, `checkout/success.blade.php`, `account/orders.blade.php`, `reseller/apply.blade.php`, `track/show.blade.php`, and `partials/product-card.blade.php` — CSS-only, no schema/route changes. Verified against `#0a68f5` (Main Company's configured theme color) in both light and dark mode via browser screenshot; full `StorefrontMenuTest`/`Storefront*` suite (58 tests) still passes; `npm run build` succeeds.
- New `App\Http\Controllers\Storefront\ContactController` (`storefront.contact` / `storefront.preview.contact`, same domain/preview pattern as `ResellerController`) renders `storefront/contact/show.blade.php`, reusing `StorefrontSetting::whatsapp_number/phone_number`, the new `contact_email`/`contact_hours` fields, `Company::address`, and the existing `CompanyFaq` list — no new model, no isolation-contract change. Migrations `2026_07_19_130000_add_contact_fields_to_storefront_settings` and `2026_07_19_140000_add_cover_image_to_storefront_pages`. `StorefrontPageResource` content field switched from `Textarea` to Filament `RichEditor` (rendered via `RichContentRenderer`, which auto-sanitizes); a plain `str_contains($content, '<')` check keeps existing plain-text pages rendering through the old paragraph-per-line path so no migration of existing content is needed. New `.storefront-richtext` CSS in `app.css` styles the rich content (no `@tailwindcss/typography` dependency added). New `tests/Feature/StorefrontContentPagesTest.php` (4 tests: Contact page full render, Contact page with nothing configured, rich-HTML page + cover image + CTA, legacy plain-text page still renders).
- Launch-readiness check "Banner uploaded" is now "Hero slide added" (an active slide exists). The storefront share (og:image) now uses the first active hero slide. `StorefrontBannerTest` rewritten around slides (product link, CTA-over-product precedence, mobile `<picture>` source).
- New `customer` auth guard/provider (`config/auth.php`) backed by the existing `Customer` model, which now implements `Authenticatable` (`password`/`remember_token`/`password_reset_code`/`password_reset_expires_at` added via migration, all hidden). Registration reuses an existing phone-matched Customer row instead of creating a duplicate, so a customer's pre-existing order history is attached to their new login automatically; a phone or email already in use by a *registered* (password-set) account blocks re-registration. New `App\Services\CustomerAccountService` (register/attemptLogin/reset-password-by-SMS-code/updatePassword — all scoped by the existing `CompanyContext`/`CompanyScope`, same as checkout/cart) and two new controllers, `Storefront\AccountAuthController` and `Storefront\AccountProfileController`, plus `storefront.account.{login,register,logout,forgot-password,reset-password,profile,password}*` routes (login/register/forgot-password throttled). Password reset re-sends via `StorefrontNotificationService::sendSms()` (new `smsConfigured()` helper) — if no SMS gateway is configured for the company, the flow says so upfront rather than silently failing; the same generic "if an account exists…" message is shown whether or not a phone matched, to avoid account enumeration. New `storefront_settings.customer_accounts_enabled` (default on) lets an owner disable the whole feature per company; `AccountOrdersController` now shows a logged-in customer's orders directly instead of asking for their phone number again. `ProductIndexController`/`PreviewController::products()` gained a `q` search param (name/SKU `LIKE`). New `tests/Feature/StorefrontCustomerAuthTest.php` (10 tests: register/login/logout, phone-record reuse, duplicate-phone block, profile/password update, SMS reset code round-trip via `Http::fake`, SMS-not-configured message, per-company disable, cross-company isolation, product search). Full `php artisan test` — 337 passed (1484 assertions); `npm run build` succeeds; browser-verified register → auto-login → profile prefill → checkout prefill → logout → login-by-email round trip on a live domain-routed company.

## [1.19.0] - 2026-07-18

**Release type:** Minor Feature Update

Automatic image optimization: every admin-uploaded image is compressed and converted to WebP before it's stored, so the storefront never serves untouched multi-megabyte camera photos. Also lays the groundwork for optional Cloudflare R2 object storage.

### Added

- **Automatic WebP compression on upload:** product featured/gallery images, category images, company logos, storefront logos, banners, and slides are all resized (product photos/banners capped at 1600px, logos/category tiles at 800px on the longest edge), stripped of EXIF metadata, and re-encoded as compressed WebP the moment they're uploaded in the admin panel. SVGs and animated GIFs/WebPs are stored untouched (re-encoding would break them).
- **Chat Channel save fix (live):** creating a Chat Channel while "All Companies" was selected silently assigned it to the default company, so it never appeared under the owner's real company (and retrying crashed on the duplicate Phone Number ID). The form now shows a required Company selector in All-Companies mode (same pattern as Courier Providers), the channel list shows a Company column in that mode, and a duplicate Phone Number ID / Page ID shows a proper validation message instead of a server error.
- **Cloudflare R2 disk (prepared, not active):** an S3-compatible `r2` filesystem disk plus `R2_*` env variables are in place for a future migration of public media off the server disk. Nothing switches over until a bucket + API token are created in the Cloudflare dashboard and the env vars are set.

### Technical Notes

- New `ImageOptimizerService` (Intervention Image v3, GD driver, WebP quality 82/85) wired into Filament `FileUpload::saveUploadedFileUsing()` via the `OptimizesUploadedImages` trait — single implementation, six forms opted in.
- New composer packages: `intervention/image` ^3.9, `league/flysystem-aws-s3-v3` ^3.25 (deploy: run `composer install`; server needs the PHP GD extension with WebP support — verified present locally).
- No schema changes, no migration. New `ImageOptimizerTest` (resize cap, no upscaling, compact cap, SVG/animated-GIF passthrough) — 5 passed. R2 disk config shape validated with throwaway credentials (adapter + URL builder resolve); no app code references the `r2` disk yet.
- Chat Channel diagnosis: channel creation was reproduced against a scratch MySQL 8.4 database (schema + encrypted-cast inserts all succeed), ruling out a SQLite-vs-MySQL schema issue — the failure is the All-Companies default-company fallback. New `ConversationChannelResourceTest` (saves into active company; All-Companies mode requires an explicit company; duplicate external_id → validation error, same ID under the other provider allowed).

## [1.18.0] - 2026-07-17

**Release type:** Minor Feature Update

Chat-order UX polish after live testing plus a WhatsApp Business-style Inbox overhaul: premium redesign of the customer order form and thank-you pages, clickable order links, catalog (product card with image) sending, near-realtime chat, pull-to-refresh in the mobile app, and fewer spurious "Error while loading page" toasts.

### Added

- **WhatsApp Business-style Inbox:** avatar + last-message-preview conversation list with unread badges, full-screen thread with back button on mobile (list ⇄ chat navigation like the WhatsApp app), chat wallpaper, date separators (আজ/গতকাল), delivery ticks (✓ sent, ✓✓ delivered/read, ⚠ failed), auto-scroll to the newest message, and a WhatsApp-style composer (rounded pill input that grows as you type, Enter sends / Shift+Enter for a new line, round send button, "+" attach button).
- **Catalog sending with product image:** the "+" button opens a catalog panel — pick a product (with live image/price preview) and quantity, and the customer receives a product card: on WhatsApp the product photo goes as an image message with name/price/order-link caption; on Messenger the photo is sent followed by the text. The image also shows in the Inbox thread bubble and on the customer's order form.
- **Chat order form + thank-you page redesign:** modern mobile-first look (Hind Siliguri Bengali font, brand mark header, gradient confirm button, focus rings, animated success checkmark, dashed order-number chip, trust footer) with full dark-mode support; the closed/expired page got the same treatment.
- **Back button on the thank-you page:** logged-in staff see "ইনবক্সে ফিরে যান" (returns to `/admin/crm/inbox`); customers see a plain "ফিরে যান" (history back).
- **Clickable links in Inbox chat bubbles:** URLs in any message (e.g. order-form links) are now real links that open in a new tab, with XSS-safe escaping.
- **Pull-to-refresh in the mobile app:** dragging down from the top of any admin page inside the Android (Capacitor) app shows a Chrome-style spinner and reloads the page. Activates only inside the app's webview — normal mobile browsers keep their native pull-to-refresh.

### Fixed

- **Sending an order form no longer closes the open chat.** The global "reload after any success notification" script was resetting the whole Inbox page (back to the empty two-pane state) every time "Order link sent." fired. The Inbox now opts out of that reload; sent messages and order forms appear in the thread instantly on the same request.
- **Messages feel realtime:** your own sends render immediately (no more waiting for the next poll), and incoming messages arrive via a 5-second visible-only poll with WhatsApp-style follow-scroll (the thread stays pinned to the newest message when you're at the bottom).
- Inbox polling runs only while the tab/app is visible (`wire:poll.visible`), so background polls on flaky mobile connections no longer surface Filament's "Error while loading page" toast for no user-visible reason. (That toast is Filament's generic notification for any failed Livewire request — a momentary network drop or a hit during a deploy triggers it.)
- **Order form quantity now recalculates the total live:** changing quantity on `/o/{token}` updates the grand total instantly in the browser (server still recomputes from real prices on submit).

### Technical Notes

- New `ConversationMessage::bodyHtml()` (escape-then-linkify, `target="_blank" rel="noopener noreferrer"`) and `mediaImageUrl()` (resolves catalog URLs and downloaded webhook media paths to displayable image URLs, images only).
- `ConversationMessengerService::send()` accepts an optional media URL — WhatsApp sends an image message with caption, Messenger sends the image then the text; the archived `conversation_messages` row stores `media_path`/`media_mime`. New `Conversation::latestMessage()` (`latestOfMany`) powers the list previews without N+1 queries.
- The `notificationsSent` reload listener now skips pages carrying a `data-zz-no-reload` attribute (the Inbox manages its own live state).
- Pull-to-refresh ships as a small vanilla-JS render hook in `AdminPanelProvider` (touch tracking with resistance, inner-scrollable detection so the thread list doesn't trigger it); detected via `window.Capacitor` / Android WebView UA.
- No schema changes. New `InboxPageTest` (reply archiving + state kept after send, catalog image on link/message, media URL resolution). Full suite: 307 passed (1282 assertions). Verified in the browser: new order form renders, live total recalculates (qty 2→5 updated ৳4,400→৳11,000 instantly), product image shows on the form; a test order previously submitted end-to-end; smoke-test data removed from the demo DB afterwards.

## [1.17.1] - 2026-07-17

**Release type:** Hotfix

Fixed a production 500 error when submitting the chat order form (`/o/{token}`) — and potentially any order-creating flow — on MySQL.

### Fixed

- `orders.status` was still defined as the original `enum('pending','processing','completed','cancelled')` even though the application has long used `draft`/`confirmed`. SQLite (local/demo) doesn't enforce enums so everything worked locally, but MySQL in strict mode rejects the `'draft'` insert, so the chat order submit crashed with a 500 on the live server. New migration converts the column to a plain `string(20)` (valid values enforced in code via `Order::STATUSES`).

### Technical Notes

- Migration `2026_07_17_030000_change_orders_status_to_string.php`; `down()` intentionally keeps the string type since reverting to the enum would reject legitimate `draft`/`confirmed` rows. Deploy needs `php artisan migrate`.
- Audited all remaining `enum()` columns: the voucher-module enums match the values the code writes; only `orders.status` was stale.
- Full suite: 304 passed (1266 assertions).

## [1.17.0] - 2026-07-17

**Release type:** Minor Feature Update

Full Lead/CRM module (`02_LEAD_CRM_MODULE_PLAN.md` steps 1–14): leads and quotations, a WhatsApp/Messenger conversation inbox with click-to-order links, and a grounded AI auto-reply assistant with a 72-hour CTWA ad reply window. Also ships multi-image product-taggable storefront banners and an app-wide single-column admin form layout.

### Added

- **Leads & Quotations (CRM nav group):** Lead resource with sources/statuses, activity log, follow-up reminders (overdue highlighted), convert-to-customer action; Quotation resource with product/variant repeater, auto totals, public share page (`/quotation/{number}`), WhatsApp share, convert-to-order (draft order flows through the existing confirm/stock/balance pipeline), and a daily `quotations:mark-expired` scheduled command.
- **Conversation Inbox:** Meta webhook endpoint (`/webhooks/meta`) with per-channel HMAC signature verification and dedupe, queued WhatsApp/Messenger message ingestion with media download, auto-linking of contacts to existing customers/leads (or auto-creating a lead), and a Filament Inbox page (unread badge, status filters, reply/notes, WhatsApp-style thread) styled with native Filament components.
- **Click-to-Order chat links:** one-tap `/o/{token}` mobile order form (Bengali) prefilled from the conversation — creates a draft order (source `chat`), locks the link, marks the lead won, and sends an automatic confirmation message back into the chat.
- **AI auto-reply assistant:** per-company tool-calling agent (Anthropic or OpenAI, admin-configurable encrypted API key) that answers only from live company data via tools (product/price lookup, FAQ, delivery charge, order-link creation). Code-enforced guardrails: every money amount in a reply is cross-checked against tool results ("Never Echo"), confidence threshold, complaint/discount keywords bypass the LLM straight to human handoff, consecutive-reply limit, 24h AI pause after a human reply, and an "I'm the assistant" transparency prefix. Handoffs set the conversation to pending with a "needs review" badge + database notification.
- **CTWA Free Entry Point:** conversations that start from a WhatsApp ad get the 72-hour reply window (vs 24h) with a live countdown badge in the Inbox.
- **FAQs resource:** keyword-matched FAQs answer instantly without an LLM call.
- **AI Assistant settings page** (super admin): provider, model, confidence threshold, reply limit, brand voice, encrypted API key (never round-tripped to the browser).
- **Storefront banners:** desktop and mobile banners are now multi-image carousels, and each banner can be tagged to a product so tapping it opens that product page.
- **Admin UX:** all Filament form/infolist sections app-wide now render in a single-column layout (37 resources touched) for a consistent, less cramped editing experience.

### Technical Notes

- New tables: `leads`, `lead_activities`, `quotations`, `quotation_items`, `conversation_channels`, `conversations`, `conversation_messages`, `chat_order_links`, `company_faqs`; new columns on `conversations`/`conversation_messages` (AI + CTWA fields) and `storefront_settings.banner_images_mobile` (with data migration from the old single mobile image). Deploy needs `php artisan migrate`; scheduler + queue worker already required.
- All new company-owned models use `BelongsToCompany` + `CompanyScope` and are registered in the `MultiCompanyIsolationTest` contract; queued jobs set/clear `CompanyContext` explicitly.
- Channel access tokens/app secrets and the per-company AI API key are encrypted at rest; all external credentials are admin-configurable settings.
- New tests: `LeadTest`, `LeadConversionTest`, `QuotationTest`, `ConversationIngestTest`, `ChatOrderLinkTest`, `AiAutoReplyTest` (all LLM/Graph API calls mocked), `StorefrontBannerTest`. Full suite: 304 passed (1266 assertions).

## [1.16.0] - 2026-07-15

**Release type:** Minor Feature Update

Added an admin-panel trigger for the WooCommerce product import, which previously only ran via a server-side `php artisan` command.

### Added

- "Sync WooCommerce" action button on the Storefront Settings edit page and list row: runs the existing `WooCommerceImportService` import on demand (with an option to skip image downloads), shows a success/failure toast with created/updated/skipped counts, and only appears once a site URL + consumer key/secret are saved for that company.

### Technical Notes

- No schema or service changes — reuses `WooCommerceImportService::importProducts()` exactly as the `woocommerce:import-products` CLI command does; the CLI command is unchanged and still works.
- New `StorefrontSettingResource::syncWooCommerceAction()` / `hasWooCommerceCredentials()` helpers shared between the list `recordActions` and `EditStorefrontSetting`'s header actions.
- Verified in the browser: button is hidden with no credentials saved, appears once a site URL + consumer key + secret are saved on a record.
- Full suite: 264 passed (1127 assertions).

## [1.15.0] - 2026-07-14

**Release type:** Minor Feature Update

Storefront redesign Phase 4 — Polish (`STOREFRONT_REDESIGN_PLAN.md`), completing the 4-phase redesign.

### Added

- Sitewide flash-sale/offer countdown banner on the homepage: admin sets a title, discount %, and end time; a live countdown (days/hours/minutes/seconds) ticks down and the banner disappears automatically once it ends.
- Scroll-reveal animation: the category grid, featured products, and each product carousel fade up into place the first time they scroll into view; respects `prefers-reduced-motion` (no animation, fully visible immediately) and degrades gracefully with no `IntersectionObserver` support.
- Best Sellers / New Arrivals sections and the category/product-card grids were already delivered in Phase 1; verified still working correctly alongside the new additions.

### Technical Notes

- New `storefront_settings` columns: `offer_title`, `offer_discount_percent`, `offer_ends_at`. New `StorefrontSetting::hasActiveOffer()` helper (title set + end time in the future).
- Scroll-reveal ships as a small custom Alpine directive (`x-reveal`, registered in `resources/js/app.js`) rather than a new dependency — no bundle-size increase from this phase (88.96 kB / 32.92 kB gzip, unchanged from Phase 1-3, still within the plan's <60KB gzip budget).
- Performance budget check: build output stayed within budget across all 4 phases; images already lazy-load with explicit dimensions from Phase 1-2 (zero added CLS risk this phase). A full formal Lighthouse audit was not run (no CI/Lighthouse tooling wired into this repo) — flagged as a follow-up if the owner wants a formal score, rather than claimed without having actually run it.
- New test: `tests/Feature/StorefrontOfferCountdownTest.php`.
- Full suite: 264 passed (1127 assertions).

This completes all 4 phases of `STOREFRONT_REDESIGN_PLAN.md`. Items explicitly deferred across the phases (flagged, not silently skipped) remain: the Intervention Image/WebP resize pipeline, a Specification tab, the import shipping-cost-breakdown panel, real bKash/Nagad gateway API, bn/en localization, and per-product-scoped offers (this phase's offer is sitewide, not per-product as the plan's admin table originally described) — each noted in its phase's entry above with the reasoning for deferring it.

## [1.14.0] - 2026-07-14

**Release type:** Minor Feature Update

Storefront redesign Phase 3 — One-Page Checkout & Payments (`STOREFRONT_REDESIGN_PLAN.md`).

### Added

- Delivery area selection (Inside / Outside Dhaka) with admin-configurable per-area delivery charges; the order summary live-updates the delivery charge and total as the customer picks an area, with no page reload.
- Payment method choice: Cash on Delivery (admin on/off toggle), and manual bKash/Nagad "Send Money" — the customer enters the number they sent from and the Transaction ID, which is captured as a `pending` `StorefrontPayment` for admin verification. Both manual methods are hidden from checkout entirely until the admin configures a receiving number.
- New admin-only "Storefront Payments" list (Filament) with Verify/Reject actions for pending manual bKash/Nagad payments.
- The success page shows a bKash/Nagad-specific "we are verifying your payment" notice for manual payments (distinct wording from the existing pre-order online-advance notice).

### Technical Notes

- New `storefront_settings` columns: `cod_enabled`, `delivery_charge_inside`, `delivery_charge_outside`, `manual_bkash_number`, `manual_bkash_instructions`, `manual_nagad_number`, `manual_nagad_instructions`.
- The customer's delivery-area choice is stored on the order's existing `shipping_zone`/`shipping_fee` columns (the same fields the generic courier `ShippingFeeService` would otherwise auto-fill) — since `Order`'s `creating` hook only auto-computes them when still `null`, passing the storefront's own charge at creation time cleanly overrides the auto-detection with no schema change and no double-charging risk.
- Manual bKash/Nagad payments reuse the existing `StorefrontPayment` model/table (`gateway` = `manual_bkash`/`manual_nagad`, `payment_method` holds the sender's number, `transaction_id` holds the TrxID) rather than adding new columns or a parallel payment table.
- All new checkout fields are optional server-side and default to the pre-Phase-3 behaviour (COD, no delivery charge) so nothing already integrating with the checkout endpoint (existing tests, any external callers) breaks without sending the new fields.
- Deliberately deferred (flagged, not silently skipped): bKash/Nagad **gateway API** integration (the plan's "bKash গেটওয়ে (API)" line) — the existing ZiniPay gateway already covers pre-order advance payments and is left as-is; a true bKash API integration is a separate, larger piece of work requiring real merchant credentials. Also deferred: bn/en `lang/` localization (plan section 5's "ভাষা" note) — no `lang/` directory exists in this repo yet; introducing full bilingual UI strings is a cross-cutting change that touches every storefront view, not scoped to checkout alone.
- New test: `tests/Feature/StorefrontManualPaymentTest.php`.
- Full suite: 261 passed (1120 assertions).

## [1.13.0] - 2026-07-14

**Release type:** Minor Feature Update

Storefront redesign Phase 2 — Product Page (`STOREFRONT_REDESIGN_PLAN.md`).

### Added

- Buy Now: a second button next to Add to cart that adds the item and redirects straight to checkout, skipping the cart page (single-variant products only).
- Sticky mobile action bar: Add to cart / Buy now stay reachable at the bottom of the screen while scrolling the product page on a phone.
- Description / Shipping & Return tabs on the product page (Alpine-powered).

### Technical Notes

- `CartController::addToCart()` now accepts an optional `buy_now=1` field on the existing cart-add POST; when present it redirects to `storefront.checkout.show` (or the preview equivalent) instead of back to the referring page. No new routes.
- The existing image gallery, tiered/wholesale pricing table, variant option table, and related-products grid were already implemented in an earlier pass and needed no changes for this phase.
- Deliberately deferred (flagged, not silently skipped): a Specification tab (no key-value spec field exists on `Product` yet — would need its own migration) and the plan's optional import shipping-cost-breakdown panel (no admin-configurable air/sea rate fields exist yet).
- New test: `tests/Feature/StorefrontBuyNowTest.php`.
- Full suite: 257 passed (1104 assertions).

## [1.12.0] - 2026-07-14

**Release type:** Minor Feature Update

Storefront redesign Phase 1 — Foundation & Home (`STOREFRONT_REDESIGN_PLAN.md`).

### Added

- Animated hero slider: new Storefront Slides admin resource (image, mobile image, heading/subheading/CTA, sort order, active toggle, optional schedule window). Multiple slides autoplay with fade transitions, dot navigation, swipe-friendly, and respect `prefers-reduced-motion`. Falls back to the existing single banner/hero copy when no slides are configured, so existing storefronts are unaffected until slides are added.
- Category images: `Category` now has an admin-uploadable image, shown on the homepage category grid (horizontal scroll on mobile, grid on desktop) with a hover zoom; falls back to the existing initial-letter tile when no image is set.
- Trust strip: three admin-configurable reassurance lines (delivery/return/payment) shown as an icon row under the hero.
- Product card v2: discount badge and struck-through compare price when `sale_price < price`; the "Add to cart" quick-add button is now always visible (not hover-only), matching mobile-first Amazon-style cards. Product card and category images now load lazily with `decoding="async"`.
- Alpine.js (~8KB gzipped) added for the hero slider's interactivity; no other framework changes.

### Technical Notes

- New table `storefront_slides` (company-owned, `BelongsToCompany`/`CompanyScope`, covered by `MultiCompanyIsolationTest`); `categories.image` and `storefront_settings.trust_strip_*` columns added.
- Homepage slide list is cached per company for 10 minutes (`storefront-home:{companyId}`), invalidated immediately on saving/deleting a `StorefrontSlide`, `Category`, or `StorefrontSetting`. Products/categories queries themselves are **not** cached yet (stock changes too frequently to risk staleness) — flagged for its own follow-up rather than rushed in.
- Full Intervention Image/WebP resize pipeline from the plan's performance section is **deliberately deferred** (flagged, not silently skipped): today's upload fields match the existing plain-`FileUpload` pattern used everywhere else in the app (Product, Company, Storefront Settings), so this ships consistently rather than introducing a one-off pipeline for just this feature.
- New test: `tests/Feature/StorefrontSlideTest.php` (active/inactive/scheduled visibility + company isolation).
- Full suite: 255 passed (1099 assertions). `npm run build` output: 88.55 kB JS (32.75 kB gzip), within the plan's <60KB gzip budget.

## [1.11.0] - 2026-07-14

**Release type:** Minor Feature Update

New Voucher & Fund Control module (`05_VOUCHER_FUND_CONTROL_MODULE_PLAN.md`).

### Added

- Credit and Debit Vouchers: a documentation/approval layer in front of the existing accounting system. A voucher goes pending → (optionally) verified → approved/rejected/cancelled; approving it books the correct existing record automatically — a Customer Payment, Supplier Payment, or Expense — so all existing due calculations, ledgers, and reports keep working unchanged. Creating payments/expenses directly, without a voucher, still works exactly as before (both paths stay supported).
- Fund Sources: named pools of money (cash, bank, mobile banking, wallet, petty cash, owner/partner investment, business profit, bank loan, customer advance, supplier credit). Account-linked types always read their balance from the existing Accounts/ledger system — never a second stored number that could drift out of sync.
- Fund Transfers: move money between two of your own accounts with a pending → approved/rejected step, recorded as a matching pair of ledger entries.
- Inventory purchases can now be funded from a Fund Source through a voucher, without ever creating an Expense — inventory is an asset conversion, not spend (enforced and covered by `AccountingRulesTest`).
- Money Receipt: a printable PDF for an approved credit voucher, reachable via a signed link that needs no login (for sharing with a customer) but can't be guessed.
- New permissions: `voucher.create/view/view_all/verify/approve/reject/cancel`, `fund_source.manage`, `fund_transfer.create/approve`, `finance.dashboard`, mapped onto the existing roles (Sales/Inventory Staff can submit vouchers; Accountant can verify; Manager/Super Admin can approve).

### Technical Notes

- New tables: `fund_sources`, `vouchers`, `voucher_attachments`, `fund_transfers`; `purchases` gained a `funding_sources` JSON column. All four new models use `BelongsToCompany`/`CompanyScope` and are covered by `MultiCompanyIsolationTest`'s contract test.
- This ships with the module plan's own documented fallback: simple inline approval logic (`VoucherService`), not the shared `ApprovalGateService` from the not-yet-built Task/Approval Workflow module (that module does not exist in this codebase yet). Migrating `verify()`/`approve()` onto a shared service later is a self-contained follow-up.
- Deliberately **not** included in this pass (flagged as follow-up, not silently skipped): automatic voucher creation from existing Purchase/Expense/SupplierPayment/Order events (module plan step 9). Wiring this in safely needs care to avoid double-booking a record that a manual voucher already created for the same event; shipping it half-done risked duplicate financial records, so it is deferred to its own change.
- `capital_investment` vouchers are Mudarabah-ready: they only move an owner-capital Fund Source/Account today, but route through `voucher.resulting_model_type`, so a future Mudarabah investor module can link its own `investments` table without changing this module (per the plan's step 8 decision).
- Full suite: 252 passed (1084 assertions).

## [1.10.0] - 2026-07-13

**Release type:** Security

Hardening pass resolving the findings from the code audit (`CODE_AUDIT_REPORT.md`).

### Security

- Storefront order tracking now requires the customer's phone number in addition to the order number. Order numbers are sequential and guessable, so the tracking page previously let anyone enumerate any order's items and totals; a matching phone is now required as a second factor (a mismatch is indistinguishable from "not found").
- The phone-only order history page no longer displays the customer's outstanding balance. A phone number is a weak secret, so the financial balance is no longer exposed there (order history itself is unchanged).

### Fixed

- Order (and purchase) numbers could collide under concurrent creation — two simultaneous checkouts could read the same sequence and fail one order on the unique index. Number generation now retries automatically on a duplicate and mints the next free value.
- Stock recomputation no longer loads a product's entire movement history into memory; it now sums in the database, which is materially faster for high-volume products.

### Changed

- Added a production environment template (`.env.production.example`) and a "Production Hardening" section to `docs/deployment.md` spelling out the three must-change settings for production: `APP_ENV=production`/`APP_DEBUG=false`, a real database instead of SQLite, and a non-`sync` queue driver with a running worker.

### Technical Notes

- New `App\Models\Concerns\GeneratesSequentialNumber` overrides `performInsert` to retry on a UNIQUE violation of the document-number column (database-agnostic; works on SQLite and MySQL/Postgres). Applied to `Order` and `Purchase`.
- New `App\Http\Controllers\Storefront\Concerns\MatchesCustomerPhone` centralizes the +880/0/formatting-tolerant phone match shared by the tracking and order-history lookups.
- `CompanyScope` and `SetCurrentCompany` now document the context contract: `none()` is fail-closed, `all()`/cleared are unscoped. `MultiCompanyIsolationTest::test_company_context_boundary_states` guards those semantics. The cleared-is-unscoped default was left in place deliberately — the storefront relies on it for guest route-model binding plus per-record ownership checks, so making it fail-closed is a larger, separate change.
- `CustomerBlacklist`'s deliberate omission of `CompanyScope` (it supports a global `company_id = NULL` entry) is now documented in-code so it isn't "fixed" by mistake.
- Minor maintainability: named the pre-order stock ceiling constant (`StorefrontCart::PREORDER_STOCK_CEILING`), replaced inline fully-qualified class references in `StorefrontCart` with imports, and routed the seeder admin password through `config('app.seed_admin_password')` instead of a raw `env()` call.
- New form-layer test `OrderFormTest` exercises the Order create screen through Livewire (the class of gap that hid the earlier Purchase-save crash).

## [1.9.4] - 2026-07-12

**Release type:** Critical Fix Update

### Fixed

- Creating or editing a Purchase with at least one item always failed with an error on "Save changes", and the item disappeared again after reloading the page — nothing was ever actually saved.

### Technical Notes

- Root cause: `purchase_items.allocated_cost` and `landed_unit_cost` are `NOT NULL` columns with a DB-level default of `0`, but those two values are computed after save (`PurchaseWorkflowService::syncLandedCosts()`); the Filament repeater form fields for them were read-only display fields that were never populated, so the initial insert explicitly bound `NULL` for both columns. In SQLite (and most databases) an explicit `NULL` in an insert bypasses the column's `DEFAULT`, so every insert hit the `NOT NULL` constraint and rolled back.
- Fix: added `->dehydrated(false)` to the `allocated_cost` and `landed_unit_cost` fields in `app/Filament/Resources/Purchases/Schemas/PurchaseForm.php`, so they're excluded from the saved payload entirely — the DB default (`0`) applies on insert, and the existing post-save sync then fills in the real computed values, same as it already did on every subsequent update.

## [1.9.3] - 2026-07-11

**Release type:** Patch

### Changed

- The Customer Success section's five separate sidebar pages (Risk Profiles, Blacklists, Risk Reviews, Risk Events, Risk Settings) are now one "Customer Success" sidebar entry with the five pages as tabs across the top, same as the earlier Courier tab consolidation.

### Technical Notes

- New `App\Filament\Clusters\CustomerSuccess` groups `CustomerRiskProfileResource`, `CustomerBlacklistResource`, `CustomerRiskReviewResource`, `CustomerRiskEventResource`, and the `CustomerRiskSettings` page — only `$cluster` set on each (replacing `$navigationGroup`), no resource/page logic changed.
- Routes moved from `/admin/customer-risk-profiles` etc. to `/admin/customer-success/customer-risk-profiles` etc. (including `/admin/customer-success/customer-risk-settings`); updated the 5 hardcoded URLs in `tests/Feature/CustomerRiskTest.php` accordingly.
- The mobile tab-dropdown's "renders behind the sticky header" bug (fixed for the Courier cluster in v1.9.1 via `.fi-dropdown-panel { z-index: 30 }`) already applies here automatically since that CSS rule is generic to all Filament dropdown panels — verified in browser, no extra fix needed.

## [1.9.2] - 2026-07-11

**Release type:** Minor Version Update

### Added

- Active users now get a notification bell alert whenever a new version is deployed to the server — it fires automatically once the deployed CHANGELOG shows a new version, no manual step needed, and points to Release Notes for details.

### Fixed

- On mobile, the gap between the header search box and the profile avatar was too wide (a visual leftover from hiding the notification bell there). Tightened it and shifted the avatar in from the screen edge.

### Technical Notes

- New `App\Console\Commands\NotifyLatestRelease` (`php artisan release:notify-deploy`), scheduled every 5 minutes in `bootstrap/app.php`. Compares `App\Support\AppRelease::latestPublished()['version']` (the CHANGELOG's top `## [x.y.z]` entry — the same source the Release Notes page already reads) against `AppSetting` key `release.last_notified_version`. On the very first run ever (no stored baseline), it only records the current version and does not notify, so existing installs aren't retroactively spammed about every past release the moment this feature ships. On every later run, a version change sends a `Filament\Notifications\Notification` to all active users via `sendToDatabase()` and updates the stored baseline.
- No deploy-script changes needed — detection is purely CHANGELOG-content-based (already deployed via `git pull`), not tied to an `APP_VERSION` env bump.
- `.fi-topbar-end`'s `column-gap` reduced from the default `1rem` to `0.375rem` in the existing `@media (max-width: 640px)` block (same `STYLES_AFTER` render hook used for the sticky header and the mobile notifications-in-profile-menu change) — closes the gap and pulls the avatar left by the same ~10px.

**Release type:** Patch

### Changed

- The Courier section's four separate sidebar pages (Providers, Bookings, Status Logs, Webhook Logs) are now one "Courier" sidebar entry with the four pages as tabs across the top of the page — click a tab to switch, no more four cluttered sidebar links.

### Fixed

- Fixed the mobile view of that tab bar (which collapses into a "Providers ▾" dropdown on narrow screens): opening it showed an empty panel because it rendered underneath the sticky page header instead of above it, hiding all four options.
- On mobile, the header notification bell is no longer a separate cramped icon — it now appears as a "Notifications" item inside the profile/avatar dropdown menu (next to Sign out and the theme switcher), and the avatar has 10px of right padding so it's no longer flush against the screen edge. Desktop is unaffected — the bell stays in the header as before.

### Technical Notes

- New `App\Filament\Clusters\Courier` (Filament's built-in Cluster feature) groups the four existing resources (`CourierProviderResource`, `CourierBookingResource`, `CourierStatusLogResource`, `CourierWebhookLogResource`) under one nav item with `SubNavigationPosition::Top` tabs — no resource logic changed, only `$cluster` set instead of `$navigationGroup`.
- Routes moved from `/admin/courier-*` to `/admin/courier/courier-*` (Filament's standard cluster URL prefixing); updated the two hardcoded URLs in `tests/Feature/CourierIntegrationTest.php` accordingly. No other code referenced the old paths.
- `AdminPanelProvider` now calls `->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')`.
- The mobile-dropdown bug's root cause was this project's own existing custom CSS making the page header `position: sticky; z-index: 20` (added in an earlier session for the sticky-header effect); the tab dropdown's panel had no explicit z-index so it rendered behind that header. Fixed by giving `.fi-dropdown-panel` (Filament's dropdown panel class, used by this tab dropdown and others like the table column manager) `z-index: 30` in the same `STYLES_AFTER` render hook.
- New `resources/views/filament/partials/mobile-notifications-menu-item.blade.php`, injected via a `PanelsRenderHook::USER_MENU_PROFILE_AFTER` hook — a real dropdown menu item (with unread-count badge) that dispatches Filament's own `open-modal` / `database-notifications` event, i.e. it opens the exact same notifications panel the topbar bell does, not a duplicate. Only visible below 640px (`.zz-mobile-notifications-item`); the topbar bell (`.fi-topbar-database-notifications-btn`) is hidden at that width instead.

## [1.9.0] - 2026-07-09

**Release type:** Minor Version Update

### Added

- Orders now get a dynamic "Shipping Fee" automatically: the customer's address is matched against the company's new Shipping Zones keyword lists (ERP Settings → Inside/Outside/Suburb Areas) to detect a zone, and that zone's fee from the company's first active courier provider's "Set Delivery Fees" is pulled in and folded into the order total. Staff can still override it manually on the order form; if no zone matches or no courier is configured yet, it defaults to BDT 0 and the field shows a note to set it manually.
- Added "Shipping Zones" to the ERP Settings page — comma-separated area/keyword lists per zone (Inside, Outside, Suburb), owner-managed, no hardcoded city list.

### Removed

- Removed the Filament "Welcome / Sign out" account widget from the admin Dashboard (visual clutter, redundant with the topbar user menu which already has sign-out).
- Removed the duplicate "Courier Delivery Cost" section on the Courier Provider form — it was an unused, visually identical duplicate of "Set Delivery Fees" (which is the section now wired up to actually affect order totals, see above).

### Technical Notes

- New `orders.shipping_zone` (nullable string) and `orders.shipping_fee` (decimal, default 0) columns; `total_amount` calculation (`OrderWorkflowService::sync()`) now includes `shipping_fee`.
- New `App\Services\ShippingFeeService::determineZone()` does a case-insensitive substring match of the customer's address against `companies.settings['shipping_zones']`; `feeFor()` combines that with the company's first active `CourierProvider`'s `settings.delivery_fees[zone]`. Both the admin Order form (live, recomputed when the customer changes) and the `Order::creating` model event (covers storefront checkout orders, which don't go through the Filament form) call into this service.
- `CompanySettingsService`/`CompanySettings` page persist the three area lists under `companies.settings.shipping_zones`, alongside the existing per-company `settings` JSON fields (dark logo, date format).

## [1.8.1] - 2026-07-08

**Release type:** Patch

### Fixed

- Fixed the Android app occasionally showing `net::ERR_SOCKET_NOT_CONNECTED` (and similar transient network errors) instead of recovering on its own, most noticeably when switching between Wi-Fi and mobile data or toggling a SIM's data connection. The WebView now retries a failed page load up to 3 times (2.5s apart) before giving up, and shows a friendly "Connection Problem" page with a Retry button if all retries fail.
- The app now also listens for connectivity returning (e.g. after airplane mode is turned off) and automatically reloads on its own if it's stuck on the error page — no manual retry needed in that case.

### Technical Notes

- New `ResilientBridgeWebViewClient` (extends Capacitor's own `BridgeWebViewClient`, not a plain `WebViewClient`, so plugin bridging and local-server URL interception keep working) retries only main-frame failures for a specific set of transient `net::ERR_*` codes (connection reset/refused, network changed, connection closed, socket not connected, name not resolved, internet disconnected) — a real 404/500 or SSL error from the app itself is left alone.
- New `NetworkMonitor` wraps `ConnectivityManager.registerNetworkCallback` to detect connectivity returning; added the `ACCESS_NETWORK_STATE` permission it needs.
- New local `android/app/src/main/assets/error.html` is the friendly error page (shown via `file:///android_asset/error.html`, never a network request itself, so it always renders even fully offline); its Retry button reloads the app's real configured server URL (read from `capacitor.config.json` via `CapConfig.getServerUrl()`, not hardcoded).
- `MainActivity` also now enables `domStorageEnabled`/`LOAD_DEFAULT` cache mode explicitly on the WebView.
- Could not run a local Android build to verify compilation (no JDK/Android SDK in this environment) — relies on the existing `build-android` CI job (GitHub Actions) to confirm it compiles; verify the next CI run before considering this fully verified.
- Optional follow-up not applied here (server-side, out of scope for this app-only fix): increasing Coolify/Traefik's `keepalive_timeout` if the error is still frequent after this fix ships.

## [1.8.0] - 2026-07-08

**Release type:** Minor Version Update

### Added

- Added a per-company admin dashboard color: each Company record now has its own "Dashboard Color" (Company Management → Companies → edit), applied panel-wide (sidebar, buttons, links) on every page — not just resource list pages — and switches instantly when the owner changes the active company in the topbar, no page reload or redeploy needed.
- Added a global auto-reload after any Filament save/create/delete action that doesn't already redirect (e.g. editing a record that stays on the same page, deleting a table row, saving a Settings page) — the page now always reflects the freshly persisted state instead of showing stale form data.

### Changed

- Renamed the "Company Settings" page to "ERP Settings" (same page, same URL) — it's now the single place for business profile and branding (logo, contact info, currency, timezone). The dashboard color lives on the Company record instead (see above), since it's a per-company property like the company's name or logo, not a global app setting.
- The "User Roles" page no longer has its own sidebar entry; it's reached via a new "Manage Roles" button on the Users page (still fully accessible, just not cluttering the main nav).

### Technical Notes

- New `companies.dashboard_color` column (hex, default `#F59E0B` — the previous static Amber), editable via a `ColorPicker` on `CompanyResource`. Deliberately a separate column from the existing storefront branding color (`StorefrontSetting.theme_color`) — the dashboard color is chosen for admin-panel readability, not customer-facing branding.
- New `App\Services\DynamicColorService` expands a single hex color into the full Filament 50–950 shade ladder (delegates to Filament's own OKLCH-based `Color::generatePalette()` rather than reimplementing HSL math, so the output matches how Filament renders any other `Color::*` palette).
- `AdminPanelProvider` injects the current company's shades as CSS custom property overrides (`--primary-50` … `--primary-950`) via a `HEAD_END` render hook that reads `CompanyContext` fresh on every request — this is why it applies to every page (list, Settings, Backups, Release Notes, Reports, etc.) and updates immediately on company switch, unlike the earlier attempt which only set Filament's static `->colors()` config once. "All Companies" mode keeps the static Amber fallback, consistent with the existing All-Companies write-action safeguard.
- The auto-reload listens for Filament's `notificationsSent` browser event (dispatched whenever a notification is flashed without an accompanying redirect) and calls `window.location.reload()`.

## [1.7.1] - 2026-07-08

**Release type:** Patch

### Fixed

- Fixed visiting `/` on the app's own domain (e.g. `app.zamzamint.com` — loaded by both the browser and the Android app shell) showing the generic marketing homepage instead of the admin panel. It now redirects to `/admin`, which shows the login page when signed out and the dashboard when signed in.

### Technical Notes

- New `ADMIN_APP_HOST` env var (`config('app.admin_host')`) names this host explicitly; when unset (local/testing), `/` keeps showing the marketing homepage as before. Production `.env` should set `ADMIN_APP_HOST=app.zamzamint.com`.

## [1.7.0] - 2026-07-08

**Release type:** Minor Version Update

### Added

- Added external cross-courier fraud check (Part 3.8 of the master plan): staff can look up a phone number's delivery success/cancel history on Pathao, Steadfast, and RedX merchant panels directly from the Order form ("Courier Fraud Check" button), using the `shahariar-ahmad/courier-fraud-checker-bd` package. Results now show inline next to the button (color-coded by the review threshold) instead of only in a notification toast.
- Storefront checkout runs the same check silently in the background (queued job, never visible to the customer and never blocks checkout); if the cross-courier success ratio is below a configurable threshold, a manager review is automatically requested using the existing courier-booking approval gate.
- Added a new "External courier success ratio review threshold" setting on the Risk Rule Settings page (default 50%).
- Added optional "External Fraud Check (Merchant Panel Login)" credentials on the Courier Provider form (Pathao/Steadfast/RedX) — separate from the existing booking API credentials, since this feature logs into the courier's own website rather than calling their booking API.

### Fixed

- Fixed the fraud-check button always reporting "no history found" for phone numbers stored in `+880`/`880` international format — the underlying package only accepts the local `01XXXXXXXXX` format. Numbers are now normalized before lookup.
- Fixed the manual "Courier Fraud Check" button returning a stale cached result (up to 24h old) after a courier's credentials were just added or changed. The manual button now always bypasses the cache; only the silent storefront background check still uses the 24h cache to limit repeated merchant-panel logins.

### Technical Notes

- Every real external lookup (not cache hits) is logged to the existing `customer_risk_events` table for audit trail; a result is only cached when at least one courier actually answered, so a temporary failure or missing-credentials result never sticks for 24h.
- A courier with no fraud-check credentials configured is silently skipped — this can never block order creation or courier booking.
- Pathao's official booking API doesn't offer a fraud-check endpoint; this feature logs into the courier's merchant website (same approach Steadfast/RedX use), which is inherently more fragile than a documented API and may need adjustment if a courier changes its website.

## [1.6.4] - 2026-07-06

**Release type:** Patch

### Fixed

- Fixed the Android app header still overlapping the status bar on Android 15 devices after the `[1.6.3]` StatusBar plugin fix. Root cause: the app targets SDK 35 (Android 15), which force-enables edge-to-edge layout system-wide — the StatusBar plugin's legacy overlay flags have no effect at that API level. Added `android:windowOptOutEdgeToEdgeEnforcement="true"` to the app theme to opt back out of the forced edge-to-edge layout.

### Technical Notes

- This opt-out attribute is only honored on Android 15 (API 35); Google has said it may stop being honored on a future Android version, at which point the fix will need to move to CSS safe-area-inset padding in the panel's layout instead.

## [1.6.3] - 2026-07-06

**Release type:** Patch

### Fixed

- Fixed the Android app header overlapping the phone's status bar, making the nav menu, search, and company selector hard to tap. Configured the `StatusBar` plugin (`overlaysWebView: false`) so the WebView content starts below the status bar instead of underneath it.

## [1.6.2] - 2026-07-06

**Release type:** Patch

### Fixed

- Fixed the `build-android` CI job failing with `error: invalid source release: 21` — Capacitor 7's Android library requires Java 21 to compile, but CI was set up with JDK 17. Bumped the CI job's JDK to 21 (Temurin).

## [1.6.1] - 2026-07-06

**Release type:** Patch

### Fixed

- Fixed the `build-android` CI job failing with "Permission denied" on `./gradlew` — Windows checkouts don't preserve the executable bit, so the committed `android/gradlew` lost it. Restored the executable bit on the file and added a `chmod +x ./gradlew` step before running it in CI as a safety net against this happening again.

## [1.6.0] - 2026-07-06

**Release type:** Minor Version Update

### Added

- Added a Capacitor-based Android app shell that loads the live admin panel (`https://app.zamzamint.com`) in a native WebView — no separate mobile codebase, login/sessions work exactly as in a browser, and web deploys show up in the app immediately.

### Technical Notes

- New `android/`, `mobile-shell/`, and `capacitor.config.json` (target URL lives here). See `mobile-shell/README.md` for build instructions.
- Added a `build-android` job to the GitHub Actions CI workflow that builds a debug APK in the cloud and uploads it as a downloadable artifact — no local Android Studio/JDK required. Local Android Studio setup remains documented as an alternative for developers who want it.
- Fixed the CI workflow's test step, which ran `php artisan test --env=testing` — the same flag now documented as forbidden, since it bypasses `phpunit.xml`'s environment overrides. Harmless in CI (no real demo database exists on the runner), but corrected for consistency.
- Storefront customer-facing app packaging is intentionally deferred to a later phase.

## [1.5.1] - 2026-07-06

**Release type:** Patch

### Added

- Added explicit test coverage confirming same-phone-multiple-name and recent-duplicate-order risk factors trigger correctly (`CustomerRiskTest`).

### Technical Notes

- Verified as complete in code: duplicate order detection, same-phone-multiple-name detection, high-COD-first-order detection, and manager/owner approval workflow before courier booking — all were already implemented in `CustomerRiskService` and gated by `assertCourierBookingAllowed`; master plan Phase 8 was marked incomplete in error and is now corrected.
- Also corrected a stale Phase 5 note: MOQ/tiered-pricing B2B enforcement is implemented and tested (`StorefrontB2bTest`), not unconfirmed.
- Added an agent rule: test runs must never refresh or reseed the demo/development database; always run `php artisan test` with no `--env` flag so `phpunit.xml`'s isolated in-memory database is used.

## [1.5.0] - 2026-07-05

**Release type:** Minor Version Update

### Added

- Added automatic courier status syncing: a scheduled `couriers:sync-statuses` command runs every 30 minutes and pulls delivery status updates from Steadfast, Pathao, RedX, and E-Courier for all active bookings, per company.
- Added persistent admin alerts (notification bell in the admin panel) when a courier provider's status sync keeps failing, when a courier webhook cannot be processed after all retries, or when bookings sit without a final delivery status for too long.
- Added a Courier Health dashboard widget: active deliveries, stale bookings, failed webhooks in the last 24 hours, and providers with sync errors.
- Added per-provider monitoring settings (stale-booking alert days, sync failure alert threshold, sync batch limit, sync cooldown) plus last-sync time and failure count visibility on the Courier Providers list.

### Technical Notes

- New `notifications` table and courier monitoring columns — run `php artisan migrate` on deploy (scheduler must already be running for the new command).
- Alerts go to active super admins and the owning company's managers, deduplicated to at most one alert per subject per day.

## [1.4.0] - 2026-07-05

**Release type:** Minor Version Update

### Added

- Added live Pathao courier integration: token-based authentication with caching, order booking from the Orders list, delivery status sync, and webhook status mapping.
- Added live RedX courier integration: parcel booking, tracking sync, and webhook status mapping.
- Added live E-Courier integration: order placement, tracking sync, cancel support, and webhook status mapping.
- Added driver-specific credential fields on the Courier Provider form (Pathao client ID/secret/username/password, RedX access token, E-Courier API key/secret/user ID) — all encrypted, owner plugs keys in later; sandbox/staging base URLs supported.
- Added a Steadfast "Balance" action on the Courier Providers list that shows the current merchant balance.
- The courier booking status sync action now works for all API providers (Steadfast, Pathao, RedX, E-Courier).

### Technical Notes

- API contracts were verified against official documentation; bookings without configured credentials fail with a clear validation message instead of silent errors.
- Live sandbox verification is still pending until merchant credentials are provided.

## [1.3.0] - 2026-07-05

**Release type:** Minor Version Update

### Added

- Added the custom storefront: per-company domain resolution, published pages, product listing/detail, cart, checkout that creates ERP orders, order tracking, and phone-verified customer order history with reorder and due-balance visibility.
- Added storefront merchandising: admin-managed product carousels, product variants in cart/checkout, and Filament-managed storefront settings (branding, theme, pages, footer links).
- Added B2B wholesale support: per-product MOQ and quantity-tiered pricing applied automatically in the cart and shown on product pages.
- Added pre-order support with per-product advance percent; cash-on-delivery remains for in-stock quantities only, while pre-order quantities require an online advance through the ZiniPay gateway (server-side verified webhook, amount-matched, never trusts the webhook body).
- Added reseller applications on the storefront with admin approval workflow on the Customer record.
- Added abandoned-cart recovery: carts persist with checkout contact, and an hourly command sends SMS (configurable GET-gateway URL template) and Meta Cloud WhatsApp template reminders.
- Added WooCommerce products-only import via the REST API (`woocommerce:import-products`), matching by SKU/slug so re-runs update instead of duplicate.
- All gateway/notification/import credentials are encrypted, per-company, admin-configurable settings — nothing is hardcoded.

### Security

- Fixed a cross-company data exposure: company context middleware ran after route model binding, so implicit-binding admin routes (for example the order PDF download) could resolve another company's record for an authenticated staff user. Company context is now bound before route model binding, with regression tests covering CSV/report exports and cross-company PDF access.

### Technical Notes

- Verified as complete in code: per-product landed cost allocation, invoice and report PDF export, scheduled daily database backups with restore-drill verification, and composer.json hardening (block-insecure, stable minimum-stability, pinned dompdf).
- Cross-cutting company-isolation audit (queue jobs, scheduled commands, exports, backups) completed and documented in the master plan.

## [1.2.0] - 2026-06-24

**Release type:** Minor Version Update

### Added

- Added explainable Customer Success and Risk Score profiles, courier success/return/cancel ratios, immutable order check history, and idempotent delivery events.
- Added global/company blacklist management and booking-time blacklist enforcement.
- Added Customer and Order risk badges plus booking-form risk visibility.
- Added Customer Success dashboard alerts, risk review approvals, risk event visibility, and configurable rule settings.

### Technical Notes

- Added disposable SQLite backup restore verification through `php artisan backup:verify`.
- Bulk Main Company data reassignment is intentionally not planned; new records will be entered under the correct company and rare historical exceptions reviewed manually.

## [1.1.0] - 2026-06-23

**Release type:** Minor Version Update

### Added

- Added backup-gated, dry-run company data reassignment tooling with transactional child-record migration.
- Added complete company-owned model isolation contract coverage.
- Added courier provider contracts, manager, Manual and Steadfast adapters, API retry/timeouts, signed idempotent queued webhooks, operational log resources, booking actions, and report aggregates.
- Added company-scoped shipment and container tracking inside each Purchase record, with status-aware draft planning and read-only received/cancelled history.

### Technical Notes

- Live Pathao, RedX, and E-Courier adapters require their official current API contracts and merchant credentials.
- Production company-data reassignment must use a reviewed mapping and pre-migration backup.

### Fixed

- Fixed Filament select, textarea, and checkbox components failing behind an HTTPS reverse proxy because lazy-loaded JavaScript URLs were generated with `http://`.

## [1.0.0] - 2026-06-21

**Release type:** Major Version Update

### Added

- Added visible app release metadata with version, release type, release date, and source commit support.
- Added an admin Release Notes page so deployed changes are visible inside the app.
- Added release policy documentation for major, minor, patch, security, hotfix, and maintenance releases.

### Fixed

- Fixed Top Business Performers cards so light mode uses light backgrounds while dark mode remains preserved.

### Technical Notes

- Added production update safety documentation covering backups, migrations, rollback, and forbidden destructive commands.
- Production updates must create a database backup before running migrations.
- Routine production deploys must not run seeders or destructive migration commands against live data.
