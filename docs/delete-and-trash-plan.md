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
3. Bulk delete: the badge shows the **number of selected records**; typing the number (Bangla digits are accepted) confirms.
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

**Soft-delete models (7):** `Order`, `Voucher` (own Trash), `Expense`, `FeedPost` (generic Trash, T1), `OrderPayment` (Trash inside the order's Payments History, T2). `FeedComment` and `InvestorNominee` are soft-deleted too but are **not something a user deletes with a Delete button**, so they get no Trash (see the T2 row).

**Hard-delete models that have a Delete button** (72 Delete buttons in 67 files, checked 2026-11-01). My proposal for each group, for the owner to confirm:

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
| T3 | Catalog + Storefront content (no money effect): Category, Offer, ProductCarousel, ProductReview, CourierProvider, StorefrontSlide / Page / PaymentMethod, CompanyFaq, QuickReply | planned |
| T4 | People & CRM: Customer, Supplier, Lead, Quotation, ConversationChannel, ResellerLevel | planned |
| T5 | Products, StockPool, Purchase (stock effect) | planned |
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

1. **Bulk delete asks for the number of selected records.** Fine, or should it ask for a word (for example `DELETE`)?
2. **Exact match:** capital and small letters must match; the copy button fills nothing, it only copies (you paste it yourself). Fine?
3. **The proposal in section 3.** Which groups should *not* go to Trash, and which need your decision first (finance payments, accounts, IMS money records)?
4. **How long does Trash keep things?** Today: until someone deletes them permanently. Should a record be removed automatically after, say, 30 / 90 days?
5. **Who may use Trash?** Today: whoever may delete that kind of record (Expenses: Super Admin only, like their delete).
6. **Stock movements:** should bulk delete of stock movements exist at all? Deleting a ledger row changes stock history.

## 6. Not verified yet

Written and linted without the app running: this machine has PHP 8.3 and the project needs 8.4 (the app does not boot). Verified by execution: `TypeToConfirmDeleteTest`, `TranslationParityTest`, `InfoTipGuardTest`, `InfoTipAuditTest`. Not run: `DeleteTypeNameAndTrashTest`, the nine existing tests changed to pass the confirmation, the full suite, a browser check of the modal and the Trash pages. The branch's CI and a run on PHP 8.4 decide.
