# Delete = type the name, then Trash — plan

Owner request, 2026-11-01. Rule for agents: `CLAUDE.md` → "Delete = type the name, then Trash".

## বাংলা সারসংক্ষেপ

**আপনি যা চেয়েছিলেন:** Delete বাটনে ক্লিক করলে ওই রেকর্ডের নাম দেখাবে (কপি করার ব্যবস্থাসহ) এবং একটি ঘরে সেই নাম বসাতে হবে। নাম না মিললে ডিলিট হবে না। কনফার্ম করলে রেকর্ড ট্র্যাশ পেজে যাবে, সেখান থেকে ফিরিয়ে আনা (Restore) বা স্থায়ীভাবে ডিলিট (Permanent delete) করা যাবে।

**এই কমিটে যা হয়েছে (ধাপ T1):**

- সব প্যানেলের (ERP, IMS Office, Reseller, Trade, Investor) প্রতিটি Filament Delete বাটনে নাম-লেখার ধাপ যুক্ত হয়েছে। নাম একটি কপি-বাটনসহ লাল ব্যাজে দেখায়। একসাথে অনেকগুলো ডিলিট করলে নামের বদলে **নির্বাচিত রেকর্ডের সংখ্যা** লিখতে হয়।
- ভাউচারের "ট্র্যাশে পাঠান" ও ট্র্যাশ থেকে "স্থায়ীভাবে ডিলিট" এ ভাউচার নম্বর লিখতে হয়। অর্ডারের ট্র্যাশ পপআপে এখন প্রতিটি অর্ডারের জন্য আলাদা "স্থায়ীভাবে ডিলিট" আছে (ইনভয়েস নম্বর লিখে), আর "সব স্থায়ীভাবে ডিলিট" এ ট্র্যাশের অর্ডার সংখ্যা লিখতে হয়।
- নতুন সাধারণ ট্র্যাশ পেজ (`TrashedRecords`)। **Expenses** আর **Office → Feed** এ এটি চালু হয়েছে: তালিকার উপরে "ট্র্যাশ (n)" বাটন, সেখানে Restore ও স্থায়ীভাবে ডিলিট। Expenses এ আগে ডিলিট করলে রেকর্ড হারিয়ে যেত (ডাটাবেসে থাকত, কিন্তু ফেরানোর কোনো পেজ ছিল না)। এখন ফেরানো যায়, এবং ফেরালে অ্যাকাউন্ট থেকে টাকাও আবার কাটা হয়।
- ভবিষ্যতের সব কাজের জন্য নিয়ম `CLAUDE.md` ও `AGENTS.md` এ লেখা হয়েছে।

**বাকি আছে:** সিস্টেমের বেশিরভাগ রেকর্ড (প্রোডাক্ট, কাস্টমার, সাপ্লায়ার ইত্যাদি) এখনও **স্থায়ীভাবে ডিলিট হয়**, ট্র্যাশে যায় না। কারণ ট্র্যাশে পাঠাতে হলে ডাটাবেসে `deleted_at` কলাম, ইউনিক ইনডেক্স, রিপোর্ট, স্টক ও টাকার হিসাব — সবকিছু মডেল ধরে ধরে মেলাতে হয়। একসাথে সব করলে ভুলের ঝুঁকি বেশি, তাই ধাপে ধাপে (নিচে সেকশন ৪)। এই মডেলগুলোর ডিলিট মডালে এখন লেখা দেখায় "এটি স্থায়ীভাবে ডিলিট হবে। এটি আর ফিরিয়ে আনা যাবে না।" — যাতে কেউ ভুল না বোঝে।

**আপনার সিদ্ধান্ত লাগবে:** সেকশন ৫ এর প্রশ্নগুলো।

## 1. What the user sees

1. Delete (row, page header or bulk) opens a modal. The record's **name** is shown in a red badge with a copy icon (click to copy). Under it an input "Type the name to confirm" with an ⓘ icon explaining the check.
2. The input must equal the name: case counts; spaces at the ends or doubled do not; Unicode forms are normalised so a pasted Bangla name always matches. A wrong text shows an error under the input and nothing is deleted.
3. Bulk delete: the badge shows the word **DELETE**; typing it (or pasting it) confirms (owner decision 2026-11-03; it was the number of selected records).
4. The modal text says what will happen: "moved to Trash… restore it, or delete it permanently, from the Trash page" (the model has a Trash page), "removed from the list" (soft-deleted but no Trash page yet), or "deleted permanently. It cannot be restored" (everything else).
5. Trash page (`/…/trash`, "Trash (n)" button in the list header): the resource's own columns plus "Moved to trash at"; row and bulk **Restore** and **Delete permanently**. Delete permanently asks for the name again.

## 2. How it is built

| Piece | File |
|---|---|
| Name prompt, matching rules, name resolver, wiring for every Filament delete action | `app/Support/TypeToConfirmDelete.php`, registered in `AppServiceProvider::boot()` |
| Generic Trash page and the "Trash (n)" header button | `app/Filament/Resources/Pages/TrashedRecords.php` |
| First users | `Expenses/Pages/TrashedExpenses.php`, `Ims/Office/Resources/FeedPosts/Pages/TrashedFeedPosts.php` |
| Own trash for money / stock records | Vouchers (`VoucherTrashService`, `TrashedVouchers`), Orders (`OrderTrashTable` modal, `Order::restored`) — type-the-name added to their actions |
| Tests | `tests/Unit/TypeToConfirmDeleteTest.php` (rules, runs without the app), `tests/Feature/DeleteTypeNameAndTrashTest.php` (flow), existing delete tests now pass `data: ['confirmation' => …]` |

The name shown is the resource's record title; when it is only the model label, the first filled of `name`, `title`, `label`, `subject`, a number column, `code`, `sku`, `slug`, `email`, `phone`; last resort "Label #id". A resource whose records have no such column defines `getRecordTitle()` + `hasRecordTitle()` (done for `StockPoolResource`: a pool is named after its source product).

## 3. Where things stand (checked 2026-11-01)

**Soft-delete models (25):** `Order`, `Voucher` (own Trash), `Expense`, `FeedPost` (generic Trash, T1), `OrderPayment` (Trash inside the order's Payments History, T2), since T3 `Category`, `Offer`, `ProductCarousel`, `ProductReview`, `StorefrontSlide`, `StorefrontPage`, `StorefrontPaymentMethod`, `CompanyFaq`, `QuickReply` (each with a Trash page), and since T4a `Customer` (Customers and Resellers each have a Trash page) and `Supplier`, and since T4b `Lead`, `LeadActivity` (inside the lead), `Quotation`, `ConversationChannel` and `ResellerLevel`, and since T5a `Purchase` and `StockPool`. `FeedComment` and `InvestorNominee` are soft-deleted too but are **not something a user deletes with a Delete button**, so they get no Trash (see the T2 row).

**Hard-delete models that have a Delete button** (72 Delete buttons in 67 files, checked 2026-11-01; the Catalog and Storefront groups below were converted in T3 except `CourierProvider` and `Media`, and `StorefrontSetting`, which is a site row and waits for the owner). My proposal for each group, for the owner to confirm:

| Group | Models | Proposal |
|---|---|---|
| Catalog | Product (bulk), Category, ProductCarousel, ProductReview (bulk), Offer, CourierProvider (bulk), Media (also removes the file) | Trash for Product, Category, Offer, ProductCarousel, ProductReview, CourierProvider. Media stays permanent (it deletes the file in storage). |
| People & CRM | Customer (bulk), Supplier (bulk), Lead (+ activities), Quotation, QuickReply, ConversationChannel | Trash. Customer and Supplier carry balances: check what a trashed one does to reports, orders, payments. |
| Purchases & stock | Purchase (bulk, + shipments), StockMovement (bulk), StockPool | Purchase: Trash only with its stock effect reversed and restored (like Order). StockMovement: stays permanent (it is a ledger row; deleting it is itself the risky act — ask owner whether bulk delete should exist at all). StockPool: Trash. |
| Finance | Account (bulk), ExpenseCategory (bulk), CustomerPayment (bulk), SupplierPayment (bulk), ExchangeRate | Payments move money: Trash only with reversal + restore like Expense. Account: owner decision. |
| Storefront | StorefrontSlide, StorefrontPage, StorefrontPaymentMethod, StorefrontSetting (bulk), CompanyFaq | Trash for slides, pages, FAQs, payment methods; settings: owner decision (a site row). |
| Resellers | ResellerLevel, Reseller payout account (portal) | Trash for levels; payout account stays permanent (the reseller removes their own saved account). |
| System | User (bulk), UserRole, Company, DocumentationArticle, KnowledgeDocument, AgreementTemplate, MetaAdAccount (bulk), ExpenseScan | Trash for articles, documents, templates, roles, users, Meta accounts. Company: stays a guarded permanent delete (`CompanyDeletionService`). |
| Technical logs | MobileCrashReport, DeploymentError | Stay permanent (clearing logs). |
| IMS (project pages) | InvestmentProject (bulk) and its Investments, Milestones, CostItems, Documents, Security instruments, Witnesses; InvestorGroup members; InvestorDraft; ProjectReport | Owner decision: money and legal records. Draft and report: Trash. |

## 4. Phases

Each phase ends with the full test suite on PHP 8.4, a look at the Trash page and a delete in Bangla and English at 1366 px and 360 px, and the owner's go-ahead.

| Phase | What | Status |
|---|---|---|
| T1 | Name prompt on every Filament delete; generic Trash page; Expenses and Office Feed; type-the-name on Voucher and Order trash; rule in `CLAUDE.md` / `AGENTS.md`; this plan | **built** (this commit) |
| T2 | Trash for the models that were already soft-deleted: **`OrderPayment`** — a "Trash (n)" button on the order's Payments History (Edit page): deleted payments, Restore (the paid amount and the account balance come back), Delete permanently (name asked). Reusable for any table inside a record: `App\Filament\Concerns\HasTrashView`. **Left as they are, on purpose:** `FeedComment` is deleted by its own writer in the Investor portal (the office only *hides* comments), so an office Restore would undo the writer's choice; `InvestorNominee` rows are the history of a nominee list that is replaced as a whole, and restoring one would break the "shares add up to 100%" rule. Both stay as soft-deleted history. | **built** (this commit) |
| T3 | Catalog + Storefront content (no money effect): **Category, Offer / Collection, ProductCarousel, ProductReview, StorefrontSlide, StorefrontPage, StorefrontPaymentMethod, CompanyFaq, QuickReply** — each gets `SoftDeletes`, a Trash page and a "Trash (n)" button. A row in Trash keeps its unique slug / name (the unique indexes are unchanged), so a Restore can never collide; forms say "possibly by an item in Trash". A product keeps its category link while the category is in Trash. Lists / pickers that strip global scopes skip Trash; history keeps it (`StorefrontPayment::paymentMethod()`). **Left out on purpose: `CourierProvider`** (a wrong delete there can break bookings; it stays a permanent delete until the owner decides). **`Media` stays permanent** (it deletes the file in storage). | **built** (this commit) |
| T4a | **Customer (also seen as Reseller) and Supplier** — the records that carry balances. Both get `SoftDeletes`, a Trash page (Customers, Resellers, Suppliers) and `->withTrashed()` on every relation that points at them, so orders, payments, vouchers, purchases and the reseller money rows keep the name and the balance keeps re-syncing while the person is in Trash. A customer or reseller in Trash cannot sign in, serves no store and gets no certificate; the reseller's slug / subdomain / domain stay reserved. **A customer in Trash keeps their phone** (owner took the default): an order, lead conversion or CSV / Nuport import for that number restores them instead of adding a second one (`Customer::matchOrRestore`); a storefront sign-up makes a fresh account and the old record then cannot be restored while the number is in use. Dues figures leave customers in Trash out. | **built** (this commit) |
| T4b | **Lead (+ its activities), Quotation, ConversationChannel, ResellerLevel.** Each gets `SoftDeletes` and a Trash page (the activities a "Trash (n)" button inside the lead). **A channel in Trash receives and sends nothing:** every webhook, job, picker and service that finds a channel with `withoutGlobalScopes()` adds `->withoutTrashed()` (a test scans for it); its chats and messages stay and reconnect on Restore; reconnecting the number through Embedded Signup restores it. **A lead in Trash** gets no broadcast or reminder, is off Needs attention (an opt-out still holds), and the website's retry returns it. **A quotation in Trash** has no public link and keeps its number reserved. **A level in Trash** stops applying (resellers fall back to the company's amounts, as a hard delete did) and applies again on Restore. **Who may delete a lead, quotation or activity** (and use their Trash): the new permission `crm.delete` (owner, 2026-11-03). | **built** |
| T5a | **Purchase (+ its lines and LC file) and StockPool.** A purchase in Trash does what a deleted one did: its stock movements are gone (the goods leave stock), its amount leaves the supplier's balance, its lines and LC file are hidden (global scope `purchaseNotInTrash`), it drops out of reports, shipment values, voucher funding and the number generator still treats its number as taken; Restore works stock and balance out again. A save while it is in Trash leaves its stored totals alone. Shipments stay on their list with the link to the purchase. **A pool in Trash is empty** (its products went back to their own ledgers) and remembers them; Restore links them again, unless the source product is gone or has joined another pool. **No bulk delete of stock movements** (owner, 2026-11-03, question 6). | **built** |
| T5b | Products (stock, variants, order lines, storefront, API) | mapped (section 7), waiting for questions A-C |
| T6 | Finance (payments, accounts) and IMS — only after the owner decides | open |
| T7 | System: users, roles, documents, articles, templates, Meta accounts | planned |

**Steps to convert one model** (the same every time):

1. Migration: `softDeletes()` (and `deleted_by` when the owner wants to see who). Name any long index (MySQL 64-character limit).
2. Model: `use SoftDeletes`; add it to the codebase map.
3. Unique rules (`slug`, `sku`, phone, email, number): decide whether a trashed row still blocks the value; use `CompanyScopedUnique` / `Rule::unique(...)->withoutTrashed()` accordingly.
4. Every place that lists, counts, exports, searches or serves the model by API: trashed rows must not appear (the global scope hides them) and history that points at a trashed row must still show it (`->withTrashed()` on those relations).
5. Money / stock: write the reverse effect on `deleted` and the re-apply on `restored`, and test both (see `Expense`, `Order`).
6. A table inside a record (relation manager) uses `HasTrashView` instead of a page. Resource: `canRestore*()`, `canForceDelete*()`; the `TrashedThings` page; `'trash'` before `'view'` in `getPages()`; `TrashedRecords::linkAction()` in the list header; `getRecordTitle()` if there is no name column.
7. Tests: delete needs the name, lands in Trash, restore, delete permanently, a person without the permission cannot open the Trash.
8. Bilingual keys, `CHANGELOG.md`, `UPDATE_NOTES.md`, codebase map.

## 5. Questions for the owner

1. **(answered 2026-11-03: the word `DELETE`)** **Bulk delete** now asks for the word `DELETE`, not the number of selected records.
2. **(answered 2026-11-03: as it is)** **Exact match:** capital and small letters must match; the copy button fills nothing, it only copies (you paste it yourself). Fine?
3. **(answered 2026-11-03: do T5b first, T6 and T7 later)** **The proposal in section 3.** Which groups should *not* go to Trash, and which need your decision first (finance payments, accounts, IMS money records)?
4. **(answered 2026-11-03: as it is)** **How long does Trash keep things?** Today: until someone deletes them permanently. Should a record be removed automatically after, say, 30 / 90 days?
5. **(answered 2026-11-03: as it is)** **Who may use Trash?** Today: whoever may delete that kind of record (Expenses: Super Admin only, like their delete).
6. **(answered 2026-11-03: no bulk delete; built in T5a)** **Stock movements:** should bulk delete of stock movements exist at all? Deleting a ledger row changes stock history.
7. **(answered 2026-11-03: fine as it is)** **Customers and suppliers with an unpaid balance (T4a):** should they be blocked from Trash, or stay counted in the dues reports while in Trash? Today their balance drops out of the totals, as it did when a deleted customer vanished.
8. **(answered 2026-11-03: fine as it is)** **A customer in Trash who orders again (T4a):** they are restored at once (the order is matched by phone). Is that right, or should such an order be flagged for staff?
9. **(answered 2026-11-03: keep it as it is; the owner numbered this "11")** **A WhatsApp-Web (QR) channel in Trash (T4b):** it stops archiving (the bridge's webhook gets "not found", as after a hard delete). The WhatsApp Web rules say the archive is permanent and the webhook code says no customer message is ever lost. Should a QR channel in Trash **keep archiving** until it is deleted permanently, or should Trash be refused for a QR channel (log out from WhatsApp Web Login instead)?
10. **(answered 2026-11-03: a permission is needed; the owner numbered this "12")** **Who may delete a lead or a quotation (T4b):** they had no delete permission of their own, so anyone who could open the CRM screens could delete (and restore) them. **Built:** the permission `crm.delete` ("CRM: Delete Leads, Quotations and Follow-up Activities"), checked in `Gate::before` for `Lead`, `Quotation` and `LeadActivity`: Delete, the Trash page and button, Restore and Delete permanently all need it. Super Admin has it; no other built-in role does (Manager and Sales Staff keep `crm.view` / `crm.manage` but cannot delete); a Super Admin ticks it for a role on the Roles screen.

**Decisions of 2026-11-03.** Questions 7 and 8: the T4a behaviour stays (a customer or supplier in Trash drops out of the dues totals; a customer in Trash who orders again is restored at once). Question 9: a QR (WhatsApp Web) channel in Trash **stops archiving** (the bridge's webhook gets "not found"), and archiving resumes on Restore; the archive already saved is never touched. Question 10: see above. The owner wrote "11" and "12" where the plan has 9 and 10; I read them as these two (the plan has no 11 or 12), and said so in the reply.

## 6. Verification status (2026-11-01)

PHP 8.4 was installed in the working container by hand during T3 (the environment's setup script still fails: `add-apt-repository` needs the system Python 3.12, not 3.13), and `npm ci && npm run build` was run, so the test suite can boot. **Run and passing on PHP 8.4:** `DeleteTypeNameAndTrashTest` (5), `TypeToConfirmDeleteTest` (10), the two T2 payment cases in `OrderManagementFeaturesTest`, `OrderTrashWorkflowTest` (6, after a fix to how T1 passed the confirmation), `CatalogTrashTest` (24), the new cases in `QuickReplyTest`, `ResellerPaymentMethodsTest` and `StorefrontApiAccountTest`, `TranslationParityTest`, the ⓘ guard tests. The full suite result is in `UPDATE_NOTES.md`. **Not done:** a look in the browser at the modal and the Trash pages in Bangla and English at 1366 px and 360 px, which each phase still owes before the owner's go-ahead.

## 7. T5b map: Product to Trash (2026-11-03, before any code)

**Today:** `Product` is a hard delete, only in the list's bulk "Delete selected" (no row or page Delete). The database refuses it (500 error) when the product has order lines (`order_items`, no cascade) or purchase lines (`restrictOnDelete`). Otherwise it cascades away: variants, stock movements, reviews, reseller products, offer items, carousel items, CRM quotation items, machine serials; and sets NULL on order header, meta ads/proposals, slides, challan lines, pool source. Only a cache clear runs on delete; no webhook.

**What changes with SoftDeletes (design):** a product in Trash is hidden from every list, picker, storefront page, API response, report and stock total, because the soft-delete scope does it; its rows are not touched (variants, ledger, reseller prices, offer/carousel lines stay). Restore brings it all back. Because nothing cascades any more, a product with orders or purchases **can** go to Trash (it could not be deleted before); its history keeps showing its name.

**Must be handled (each gets a test):**
1. History keeps the name: the 16 `belongsTo(Product::class)` on order/purchase/stock-movement/quotation/challan/review/reseller/offer/serial/meta rows use `->withTrashed()` where they show history (not where they would offer it for new use).
2. Raw SQL: `AccountBalanceService` (stock value, 2 queries), `ReportService` (top products join), `ResellerOrderPlacementService` join, plus `StorefrontCatalogService` counts: add `deleted_at IS NULL` (or `withTrashed` on purpose for history reports).
3. `Product::withoutGlobalScopes()` in 10 places (Inbox, StorefrontSettingResource, StorefrontSlideResource x3, StorefrontCartRecordResource, ProductVariant, CompanyStorageMigrator, BackfillMediaHub, StorefrontSlide->product): add `->withoutTrashed()` for pickers and public pages; maintenance commands include Trash on purpose.
4. Unique values: `(company_id, sku)` and `(company_id, barcode)` unique indexes stay, so a product in Trash holds its SKU/barcode/slug. `uniqueSlug`, the form's unique rules, CSV import, WooCommerce import, API `ProductController`/`OrderController` (match by SKU) use `withTrashed()` and say "in Trash" instead of failing with a database error.
5. Stock pools: a pool member or source in Trash. A product in Trash leaves the pool like a pool in Trash does (remembered, re-linked on Restore); restore is refused if the pool no longer exists or the product's pool changed.
6. Stock: a product in Trash drops out of stock totals/value; its ledger rows stay. Orders already placed for it keep their stock effect (no change to order logic).
7. Webhooks and cache: `product.updated` (price/stock) is unchanged; Trash and Restore clear the storefront listing cache (`deleted` already does; add `restored`). Whether to also tell the Next.js site, see question B.
8. Open carts/quotations/offers that contain the product: the line stays but cannot be ordered (the scope hides the product); the storefront cart drops it as it does for an inactive product.
9. `ProductsTable`: bulk delete (type DELETE) becomes "Move to trash"; row Delete on Edit page; Trash page `TrashedProducts`; `TrashFollowsDelete`; permission `inventory.delete` (Product is in `User::MODEL_MODULES` as `inventory`; the same module as stock movements).
10. Tests: `ProductTrashTest` (delete/restore, history name, hidden from list/API/storefront/reports/stock value, SKU held, import/API by SKU, pool leave and restore, permission) plus the scan test for `Product::withoutGlobalScopes()` without `withoutTrashed()`.

**Questions for the owner (asked 2026-11-03):** A) may a product with order or purchase history go to Trash (it could not be deleted before)? B) should the Next.js site be told at once when a product goes to Trash? C) the SKU/barcode of a product in Trash stays reserved (import and API say "in Trash"); or should Trash free them?
