# Accounts & Finance
> Last checked: 2026-11-01, branch `claude/upbeat-franklin-hy6fhr` (Expenses got a Trash page; type-the-name on Voucher / Order trash); before it 2026-10-24, `feature/ims-fund-source-accounts` (fund sources made from money accounts); before it 2026-10-23, `feature/trade-portal-cash-book` (Trade Portal cash book)

## What it does
The Accounts & Finance module manages tenant-level financial accounting, multi-source cash flow, expenditure tracking, and automated financial reporting. It provides double-entry fund tracking across cash, bank, mobile banking, and digital wallet accounts through an immutable transaction ledger. It implements a structured multi-stage voucher approval system (pending, verified, approved, rejected, cancelled) with soft-delete trash governance. For day-to-day bookkeeping, it processes operational expenses, supplier payments, and AI/OCR-assisted optical expense receipt scanning. Additionally, it compiles profit-and-loss statements, cash flow audits, customer balance ledgers, and downloadable supplier payment reports.

## Where to start
- `app/Models/Voucher.php` — Core financial voucher entity supporting debit/credit classification, payment attachments, and sequential numbers.
- `app/Services/VoucherService.php` — Primary accounting workflow service governing voucher verification, approval, ledger posting, and expense invariants.
- `app/Services/VoucherTrashService.php` — Soft-delete and trash management service preventing destruction of money-moving approved vouchers.
- `app/Models/Account.php` — Financial account model supporting standard fund accounts and dynamically calculated system accounts.
- `app/Services/AccountBalanceService.php` — Dynamic calculator resolving real-time inventory valuations, customer dues, and shipment asset values.
- `app/Services/FundTransferService.php` — Dedicated service executing inter-account fund transfers with debit and credit ledger pairs.
- `app/Filament/Resources/Vouchers/VoucherResource.php` — Main Filament interface for voucher preparation, status transitions, and receipt generation.
- `app/Filament/Pages/Reports.php` — Reporting hub for financial statements, sales reports, inventory values, and PDF exports.

## Files

### Models
| Path | What it does |
| --- | --- |
| `app/Models/Account.php` | Bank, cash, mobile banking, or virtual system account tracking live balances. |
| `app/Models/Voucher.php` | Primary financial voucher tracking debit/credit amounts, approvals, and transaction types. |
| `app/Models/VoucherAttachment.php` | File receipts and paper voucher scans attached to vouchers. |
| `app/Models/FundSource.php` | Funding source linked to an account; its admin page is retired, so IMS pickers list money accounts and make the account's fund source on first use (`moneyAccountOptions()`, `forAccount()`, `accountIdFor()`). |
| `app/Models/FundTransfer.php` | Records movement of funds between two company accounts. |
| `app/Models/TransactionLedger.php` | Immutable double-entry debit and credit ledger for each account. |
| `app/Models/Expense.php` | Operational business expenses linked to approved vouchers and expense categories. |
| `app/Models/ExpenseCategory.php` | Taxonomy of business expense heads (utilities, rent, salaries, packaging). |
| `app/Models/ExpenseScan.php` | Scanned receipt records containing OCR extraction results, merchant, and total. |
| `app/Models/ExpenseScanItem.php` | Parsed line items extracted from scanned expense paper receipts. |

### Services
| Path | What it does |
| --- | --- |
| `app/Services/VoucherService.php` | Enforces accounting rules, handles voucher approvals, and updates transaction ledgers. |
| `app/Services/VoucherTrashService.php` | Governs moving unapproved vouchers to trash and prevents deletion of approved vouchers. |
| `app/Services/VoucherLinkedPayoutsService.php` | Reconciles investor and reseller payouts when linked vouchers are approved or voided. |
| `app/Services/FundTransferService.php` | Executes fund transfers by debiting source and crediting destination accounts. |
| `app/Services/AccountBalanceService.php` | Dynamically evaluates real-time system balances (inventory value, customer dues). |
| `app/Services/ReportService.php` | Aggregates income, expenses, profit/loss, and balance sheet metrics. |
| `app/Services/ReportExportService.php` | Formats and streams accounting ledger data to CSV and Excel exports. |
| `app/Services/TradeCashBookService.php` | Trade Portal cash book and simple balance sheet, read from `TransactionLedger` of the manual accounts (no ledger of its own): `day()`, `month()`, `balanceSheet()`, `openingOn()`; entries through the existing models — `moveMoney()` (FundTransfer submitted and approved via `FundTransferService::approve()`, ledger rows re-dated to the chosen day), `paySupplier()` (`SupplierPayment`), `recordCost()` (`Expense`, new category via `ExpenseCategory::createWithUniqueSlug()`). Moves between own accounts are shown but not counted as money in/out. An account's opening balance counts from the start of the book. |
| `app/Services/SupplierReportService.php` | Generates supplier purchase balances and payment settlement statements. |
| `app/Services/ExpenseScan/ExpenseScanConfigService.php` | Manages AI/OCR credentials and model parameters for receipt scanning. |
| `app/Services/ExpenseScan/ExpenseScanPublisher.php` | Converts reviewed OCR receipt data into approved business expenses and vouchers. |
| `app/Services/ExpenseScan/ExpenseScanReader.php` | Interfaces with vision LLM models to parse receipt merchant, date, tax, and item lines. |

### Filament Clusters, Resources, Pages & Widgets
| Path | What it does |
| --- | --- |
| `app/Filament/Clusters/Finance.php` | Navigation cluster grouping vouchers, accounts, fund transfers, expenses, and scans. |
| `app/Filament/Clusters/Reports.php` | Navigation cluster grouping financial statements and supplier performance reports. |
| `app/Filament/Resources/Accounts/AccountResource.php` | Manages cash, bank, and mobile financial accounts and ledger histories. |
| `app/Filament/Resources/Vouchers/VoucherResource.php` | Main voucher registry with filters for status, approval stage, and debit/credit. |
| `app/Filament/Resources/Vouchers/Pages/ListVouchers.php` | Tabbed voucher listing with status tabs (Pending, Verified, Approved, Rejected). |
| `app/Filament/Resources/Vouchers/Pages/CreateVoucher.php` | Creation form for credit/debit vouchers with invoice and supplier linkers. |
| `app/Filament/Resources/Vouchers/Pages/EditVoucher.php` | Edit page for adjusting pending voucher details before verification. |
| `app/Filament/Resources/Vouchers/Pages/ViewVoucher.php` | Infolist displaying voucher metadata, audit trail, and print actions. |
| `app/Filament/Resources/Vouchers/Pages/TrashedVouchers.php` | Dedicated page for viewing trashed vouchers with restore or force-delete options. |
| `app/Filament/Resources/FundSources/FundSourceResource.php` | Retired (no pages, hidden from navigation since July 2026); kept for its form/table definition only. |
| `app/Filament/Resources/FundTransfers/FundTransferResource.php` | Manages transfer requests between internal bank and cash accounts. |
| `app/Filament/Resources/TransactionLedgers/TransactionLedgerResource.php` | Read-only ledger audit trail showing debit, credit, balance, and reference links. |
| `app/Filament/Resources/Expenses/ExpenseResource.php` | Registry of operational expenditures and category allocations. |
| `app/Filament/Resources/Expenses/Pages/TrashedExpenses.php` | Trash page for deleted expenses (restore puts the money movement back on the account unless the balance would go negative; Super Admin only, like Delete). |
| `app/Filament/Resources/ExpenseCategories/ExpenseCategoryResource.php` | Taxonomy of operational expense categories. |
| `app/Filament/Resources/ExpenseScans/ExpenseScanResource.php` | Interface for uploading and reviewing AI-scanned receipt documents. |
| `app/Filament/Resources/ExpenseScans/Pages/ReviewExpenseScan.php` | Interactive review screen to edit OCR-parsed items before publishing to expenses. |
| `app/Filament/Pages/Reports.php` | Financial report builder with date range pickers and PDF/CSV download actions. |
| `app/Filament/Pages/SupplierReport.php` | Supplier ledger statement generator and PDF export interface. |
| `app/Filament/Pages/ExpenseScanSettings.php` | Settings page for configuring receipt scan model providers and prompts. |
| `app/Filament/Resources/Vouchers/Widgets/VoucherSummaryWidget.php` | Summary widget displaying total credits, total debits, and pending approvals. |
| `app/Filament/Resources/Vouchers/Widgets/FundTransfersWidget.php` | Summary widget showing recent fund transfers and liquidity shifts. |
| `app/Filament/Resources/Accounts/Widgets/AccountSummaryWidget.php` | Widget detailing total cash on hand, bank balances, and customer credit dues. |
| `app/Filament/Resources/Expenses/Widgets/ExpenseCategorySummaryWidget.php` | Breakdown of current month expenditures grouped by expense category. |

### Controllers
| Path | What it does |
| --- | --- |
| `app/Http/Controllers/Admin/VoucherReceiptController.php` | Generates official printable voucher receipts with signature blocks. |
| `app/Http/Controllers/Admin/VoucherAttachmentDownloadController.php` | Securely streams uploaded voucher attachments to authorized accountants. |
| `app/Http/Controllers/Admin/ExpenseScanImageController.php` | Securely serves scanned receipt image files for the review screen. |
| `app/Http/Controllers/Admin/ReportExportController.php` | Exports accounting ledgers and summaries to CSV format. |
| `app/Http/Controllers/Admin/ReportPdfController.php` | Generates styled printable PDF financial statements. |
| `app/Http/Controllers/Admin/SupplierReportPdfController.php` | Generates supplier balance statements in PDF format. |

### Jobs
| Path | What it does |
| --- | --- |
| `app/Jobs/ProcessExpenseScanJob.php` | Background job executing vision OCR parsing against uploaded receipt images. |

### Views
| Path | What it does |
| --- | --- |
| `resources/views/vouchers/receipt.blade.php` | Printable voucher receipt layout with company branding and signature lines. |
| `resources/views/filament/pages/reports.blade.php` | Financial statement report builder interface. |
| `resources/views/filament/pages/supplier-report.blade.php` | Supplier statement generator view. |
| `resources/views/filament/pages/expense-scan-settings.blade.php` | Settings view for expense scan configuration. |
| `resources/views/reports/pdf.blade.php` | Printable PDF view for balance sheets and income statements. |
| `resources/views/reports/supplier-pdf.blade.php` | Printable PDF layout for supplier accounts. |

### Migrations (name pattern only)
- Find a table's migrations by searching `database/migrations/` for the table name: several tables are created together in one migration, so the file name does not always match the table.
- `database/migrations/*create_accounts_table.php`
- `database/migrations/*create_transaction_ledgers_table.php`
- `database/migrations/*create_expenses_table.php`
- `database/migrations/*create_expense_categories_table.php`

### Tests
| Path | What it does |
| --- | --- |
| `tests/Feature/AccountingRulesTest.php` | Asserts non-expense transaction types never create Expense rows. |
| `tests/Feature/AccountsAndPaymentsTest.php` | Tests account balance adjustments upon invoice and payment vouchers. |
| `tests/Feature/PermanentSystemAccountsTest.php` | Verifies system accounts (inventory, customer due) cannot be deleted. |
| `tests/Feature/VoucherWorkflowTest.php` | Tests voucher verification, approval, rejection, and ledger debit/credit posting. |
| `tests/Feature/VoucherTrashTest.php` | Ensures approved vouchers cannot be trashed and verifies soft-delete restores. |
| `tests/Feature/VoucherIsolationTest.php` | Asserts financial vouchers and accounts remain isolated to active companies. |
| `tests/Feature/ExpenseScanTest.php` | Tests OCR receipt parsing, item line extraction, and expense publication. |
| `tests/Feature/ReportsTest.php` | Tests financial report date filtering and export calculations. |
| `tests/Feature/TradeCashBookServiceTest.php` | Tests the Trade Portal cash book day/month figures, the balance sheet, the ERP refusals and company isolation. |
| `tests/Feature/SupplierReportTest.php` | Tests supplier account statement aggregation and PDF downloads. |

## Routes and panels
- **Panels**:
  - `admin` panel:
    - Finance Cluster (`admin/finance/*`): Vouchers (`admin/finance/vouchers`), Accounts (`admin/finance/accounts`), Fund Sources (`admin/finance/fund-sources`), Fund Transfers (`admin/finance/fund-transfers`), Transaction Ledgers (`admin/finance/transaction-ledgers`), Expenses (`admin/finance/expenses`), Expense Categories (`admin/finance/expense-categories`), Expense Scans (`admin/finance/expense-scans`).
    - Reports Cluster (`admin/reports/*`): Financial Reports (`admin/reports/reports`), Supplier Reports (`admin/reports/supplier-report`).
- **Admin HTTP Routes**:
  - `GET|HEAD vouchers/{voucher:voucher_number}/receipt` (name `vouchers.receipt`) -> `App\Http\Controllers\Admin\VoucherReceiptController`
  - `GET|HEAD admin/voucher-attachments/{attachment}/download` (name `voucher-attachments.download`) -> `App\Http\Controllers\Admin\VoucherAttachmentDownloadController`
  - `GET|HEAD admin/expense-scans/{scan}/images/{index}` (name `expense-scans.image`) -> `App\Http\Controllers\Admin\ExpenseScanImageController`
  - `GET|HEAD admin/reports/export/{type}` (name `reports.export`) -> `App\Http\Controllers\Admin\ReportExportController`
  - `GET|HEAD admin/reports/export/{type}/pdf` (name `reports.export.pdf`) -> `App\Http\Controllers\Admin\ReportPdfController`
  - `GET|HEAD admin/reports/supplier/{supplier}/pdf` (name `reports.supplier.pdf`) -> `App\Http\Controllers\Admin\SupplierReportPdfController`

## Data
- `accounts`: Financial accounts. Key columns: `id`, `company_id`, `name`, `type`, `opening_balance`, `current_balance`, `is_active`, `system_key`, `created_at`, `updated_at`.
- `vouchers`: Financial transactions. Key columns: `id`, `company_id`, `voucher_number`, `type` (`credit`, `debit`), `status` (`pending`, `verified`, `approved`, `rejected`, `cancelled`), `transaction_type` (`inventory_purchase`, `business_expense`, `capital_investment`, `owner_withdrawal`, `supplier_payment`, `customer_payment`, `loan`, `refund`, `asset_purchase`, `fund_transfer`, `other`), `amount`, `currency`, `customer_id`, `supplier_id`, `order_id`, `purchase_id`, `expense_category_id`, `fund_source_id`, `account_id`, `payment_method`, `transaction_id`, `confirmation_source`, `purpose`, `remarks`, `submitted_by`, `verified_by`, `approved_by`, `verified_at`, `approved_at`, `rejection_reason`, `resulting_model_type`, `resulting_model_id`, `deleted_at`, `deleted_by`, `created_at`, `updated_at`.
- `voucher_attachments`: Uploaded proof and receipt files. Key columns: `id`, `company_id`, `voucher_id`, `file_path`, `file_type`, `label`, `created_at`, `updated_at`.
- `fund_sources`: Configured funding sources. Key columns: `id`, `company_id`, `name`, `type`, `account_id`, `opening_balance`, `is_active`, `created_at`, `updated_at`.
- `fund_transfers`: Inter-account fund transfers. Key columns: `id`, `company_id`, `transfer_number`, `from_account_id`, `to_account_id`, `amount`, `status` (`pending`, `approved`, `rejected`), `remarks`, `requested_by`, `approved_by`, `approved_at`, `transaction_cost`, `created_at`, `updated_at`.
- `transaction_ledgers`: Double-entry transaction ledger. Key columns: `id`, `company_id`, `account_id`, `type`, `direction`, `amount`, `reference_type`, `reference_id`, `transaction_date`, `note`, `created_at`, `updated_at`.
- `expenses`: Operational expenses. Key columns: `id`, `company_id`, `expense_number`, `expense_category_id`, `account_id`, `amount`, `expense_date`, `reference`, `note`, `expense_scan_id`, `created_at`, `updated_at`.
- `expense_categories`: Expense taxonomy. Key columns: `id`, `company_id`, `name`, `slug`, `description`, `is_active`, `created_at`, `updated_at`.
- `expense_scans`: Scanned receipt documents. Key columns: `id`, `company_id`, `user_id`, `default_account_id`, `image_paths` (JSON), `status`, `error_message`, `api_format`, `model`, `ai_response` (JSON), `processed_at`, `published_at`, `published_by`, `source_text`, `created_at`, `updated_at`.
- `expense_scan_items`: OCR-extracted receipt items. Key columns: `id`, `company_id`, `expense_scan_id`, `expense_date`, `description`, `amount`, `expense_category_id`, `new_category_name`, `account_id`, `reference`, `note`, `confidence`, `sort_order`, `expense_id`, `created_at`, `updated_at`.

## Rules that matter
- **Non-Expense Invariant**: `inventory_purchase`, `capital_investment`, `owner_withdrawal`, `asset_purchase`, `loan`, `fund_transfer`, and `investor_payout` transaction types NEVER create an `Expense` record — they reallocate funds between asset/liability accounts, not business spend. Enforced by `app/Services/VoucherService.php`.
- **Dedicated Fund Transfer Flow**: Moving funds between internal accounts must go through `FundTransferService`, never a generic voucher. Submitting a voucher with `transaction_type = 'fund_transfer'` throws a validation exception. Enforced by `app/Services/VoucherService.php`.
- **Approved Voucher Trash Immutability**: A voucher is never deleted directly; it moves to Trash first, where only users with `voucher.delete` permission can permanently remove it. Once a voucher reaches `status = 'approved'`, it has moved real ledger balances and CANNOT be moved to Trash. Enforced by `app/Services/VoucherTrashService.php`.
- **Dynamic System Accounts**: Accounts with system keys (`inventory_value`, `customer_due`, `new_shipment`) cannot be deleted or manually adjusted; their balances are computed on-the-fly from live SQL aggregates by `AccountBalanceService`. Enforced by `app/Models/Account.php` and `app/Services/AccountBalanceService.php`.
- **Sequential Voucher Numbering**: Every voucher automatically generates a sequential reference number upon creation (`VOU-YYYYMMDD-XXXX`). Enforced by `app/Models/Voucher.php`.
- **Expense Scan Publication Gate**: OCR-scanned receipts create `ExpenseScan` records with line items in `pending_review` state; they do not alter accounts or create official `Expense` rows until staff explicitly reviews and publishes them via `ExpenseScanPublisher`. Enforced by `app/Services/ExpenseScan/ExpenseScanPublisher.php`.

## Links to other modules
- **core-platform.md**: All financial models use `BelongsToCompany` and are constrained by `CompanyScope`.
- **catalog-inventory.md**: Inventory valuations computed in `AccountBalanceService` read directly from `products` and `product_variants`.
- **sales-orders-couriers.md**: Customer order payments create credit vouchers, and courier COD remittances settle into financial accounts.
- **investments-ims.md**: Investor capital deposits and profit payout batches generate vouchers and link through `VoucherLinkedPayoutsService`.
- **reseller.md**: Reseller commission payout requests create vouchers and disburse funds through `ResellerPayoutService`.

## Related docs
- `05_VOUCHER_FUND_CONTROL_MODULE_PLAN.md` — Complete architecture plan for vouchers, fund sources, and ledger rules.
- `docs/roles-and-permissions.md` — Financial permissions (`voucher.create`, `voucher.verify`, `voucher.approve`, `fund_source.manage`).
- `docs/screenshots/reports-page.png` — Visual reference for financial reporting dashboards.
