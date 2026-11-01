# Core Platform
> Last checked: 2026-11-01, branch `claude/upbeat-franklin-hy6fhr` (delete = type the name, then Trash: `TypeToConfirmDelete`, generic `TrashedRecords` page, `HasTrashView` for relation managers, `TrashFollowsDelete` for resources; T3 gave the catalog / storefront rows a Trash); before it the same day, the ⓘ instruction-icon foundation (`InfoTip`, `InfoTipAudit`, guard test), after main with the Trade Portal test-data cleanup command

## What it does
The Core Platform provides the multi-tenant architectural foundation, role-based access control, and operational infrastructure for the entire ERP system. It isolates company data using global query scopes and automated tenant attribute injection on Eloquent models. It manages administrative users, custom roles with granular permission arrays, and five distinct Filament 5 panels (Admin, IMS Office, Investor Portal, Reseller Portal, Trade Portal). It implements system resilience through ModuleGuard failure isolation, rate-limited deployment error reporting, and defensive error pages. Additionally, it handles system-wide operations including automated encrypted backups to Cloudflare R2 and/or Google Drive, Coolify deployments, database schema migrations, and pre-authentication bcrypt protection via LoginGate.

## Where to start
- `app/Models/Concerns/BelongsToCompany.php` — Trait that automatically assigns the active company ID on model creation and registers `CompanyScope`.
- `app/Scopes/CompanyScope.php` — Global Eloquent scope enforcing tenant query isolation based on `CompanyContext`.
- `app/Services/CompanyContext.php` — Request-scoped singleton managing the active company context (`set`, `all`, `none`, `clear`).
- `app/Http/Middleware/SetCurrentCompany.php` — Middleware establishing the active company context from session or user assignment.
- `bootstrap/app.php` — Application bootstrap configuring global middleware pipeline, scheduled cron jobs, and exception isolation.
- `app/Support/ModuleGuard.php` — Failure isolation wrapper protecting widgets and navigation badges from crashing entire panels.
- `app/Providers/Filament/AdminPanelProvider.php` — Main Filament 5 panel configuration for the ERP admin interface.
- `app/Services/LoginGate.php` — Pre-hash brute-force and credential stuffing protection gate.

## Files

### Models
| Path | What it does |
| --- | --- |
| `app/Models/Company.php` | Multi-tenant company entity storing profile, settings, currency, domains, and storage disk. |
| `app/Models/CompanyApiKey.php` | API credentials for external integrations scoped to a specific company. |
| `app/Models/CompanyFaq.php` | Company-specific FAQs displayed on customer storefronts and portals. Soft-deleted (T3). |
| `app/Models/User.php` | System user model implementing `FilamentUser`, handling roles, permissions, and panel access. |
| `app/Models/UserRole.php` | Role definitions with customizable JSON permission arrays. |
| `app/Models/AppSetting.php` | Global system key-value storage with optional string encryption. |
| `app/Models/DeploymentError.php` | Persistent log of caught deployment, migration, or module runtime errors. |
| `app/Models/AuditLog.php` | Security audit trail recording model mutations, user actions, and IP addresses. |
| `app/Models/BackupRun.php` | One backup attempt (scope database/app, kind hourly/daily/predeploy/prerestore/manual, status, file, size, SHA-256, error, `note`, `offsite_at` = when the off-site copy arrived, `offsite_to` = which places got it, e.g. `r2,drive`), a restore test (scope `drill`, kind `local`/`drive`/`r2`) or a restore (scope `restore`, kind `database`/`files`/`both`). Global, no `company_id`. |

### Concerns & Scopes
| Path | What it does |
| --- | --- |
| `app/Models/Concerns/BelongsToCompany.php` | Trait injecting `company_id` on creation and attaching `CompanyScope`. |
| `app/Models/Concerns/ValidatesEmailAddress.php` | Reusable RFC-compliant email validation hook for models. |
| `app/Scopes/CompanyScope.php` | Global query scope filtering records by `CompanyContext::id` or failing closed (`1 = 0`). |

### Services
| Path | What it does |
| --- | --- |
| `app/Services/CompanyContext.php` | State container for the active request's tenant company context. |
| `app/Services/CompanySettingsService.php` | Manages tenant-specific configuration, branding, currencies, and fallback app settings. |
| `app/Services/CompanyStorageService.php` | Resolves tenant storage disks, base paths, and URL generation. |
| `app/Services/CompanyStorageMigrator.php` | Migrates company assets between local, public, and S3-compatible cloud storage. |
| `app/Services/CompanyDataMigrationService.php` | Exports and imports tenant-specific datasets across installations. |
| `app/Services/CompanyDeletionService.php` | Safely purges or soft-deletes company data while enforcing integrity constraints. |
| `app/Services/StorageSettingsService.php` | Configures S3, Cloudflare R2, and local filesystem credentials. |
| `app/Services/BackupSettingsService.php` | Backup settings that the admin can change on the Backups / R2 Backups pages: Google Drive (service account JSON encrypted) and Cloudflare R2 (`r2Account()` → `r2Endpoint()`, bucket, folder prefix, keys with the secret encrypted, dollar→taka rate); a saved value wins over the `BACKUP_R2_*` environment. |
| `app/Services/DatabaseBackupService.php` | Database backups by kind: SQLite copy or MySQL `mysqldump` streamed through gzip (`.sql.gz`, atomic rename, SHA-256), per-kind `prune()` (also when the upload failed), `verifyRestore()` for SQLite and `.sql.gz`; `create($connection, $kind, $upload, $automatic)` sends the new file off-site inside the same recorded run; `loadDump()` streams a `.sql.gz` through the `mysql` client into a database (used by the restore test and by `backup:restore`). |
| `app/Services/BackupRunRecorder.php` | Wraps a backup in a `backup_runs` row; recording errors never stop a backup; first failure of a scheduled kind alerts Super Admins via `DeploymentErrorReporter`. `recordFinished()` writes a run after the fact (a restore replaces the table) and `discardUnfinished()` drops the "running" rows a restored dump carries. |
| `app/Services/BackupDestination.php` | The contract of an off-site place (`key()`, `label()`, `isEnabled()`, `autoUploadEnabled()`, `isConfigured()`, `assertReady()`, `upload()`, `listBackupFiles()`, `download()`, `delete()`), implemented by Google Drive and Cloudflare R2. |
| `app/Services/R2BackupService.php` | Cloudflare R2 through the S3 API (`aws-sdk-php`, path-style endpoint, region `auto`, checksums only when required): one request for a small file, parts with resume and abort for a big one, stored size checked, listing limited to the folder, download to disk, delete; refuses ids outside the folder; `testConnection()`; errors explained in words. `withClient()` swaps the client (tests). |
| `app/Services/R2UsageReport.php` | What is in the R2 backup folder (count, bytes, groups by scope and kind, latest files, other files), kept for 10 minutes in the cache; a failed read is a state, not an exception. |
| `app/Services/R2CostEstimator.php` | The estimated R2 bill from `backup.r2.pricing`: `bill()` (storage, Class A, Class B, free allowance, taka), `operationsPerUpload()`, `monthToDate()` from `backup_runs`, `forecast()` of the full schedule, `scenarios()` (1×/2×/5×/10×), `example()` with typical sizes. |
| `app/Services/GoogleDriveBackupService.php` | Google Drive service-account client (a `BackupDestination`): `upload()` (backup files) and `uploadStream()` (any file into a named folder) use the resumable upload, in 8 MiB pieces with retry for big files; `listBackupFiles()`, `download()` and `delete()` for the backup folder; access token cached per instance. |
| `app/Services/OffsiteBackupService.php` | The off-site copy to every place that is on (R2 first, then Drive): `all()` / `destinations()` / `autoDestinations()` / `destination($key)`, `shouldAutoUpload($scope, $kind)` (some place on with Auto upload + kind in `backup.offsite`), `send($path, $automatic)` (refuses unencrypted unless `backup.offsite.require_encryption` is off; encrypts once to a staging file, uploads to each place, one place failing does not stop the other and is reported as `backup:offsite:{key}`, an error only when none got it), `latestDatabaseDump($place)` / `fetch()` (restore and restore test), `prune()` / `pruneDestination()` (the newest `backup.offsite.keep` copies per scope and kind, only files named like our backups; a failure is reported as `backup:offsite-prune`, never fails the backup). |
| `app/Services/BackupRestoreDrill.php` | The restore test: verifies the newest dump (or the newest R2 / Drive copy, downloaded and decrypted), loads it through the `mysql` client into a scratch database `backup_drill_{time}_{random}` on the limited `backup_drill` connection, checks table count and `migrations`, always drops the scratch database, removes day-old leftovers; recorded as scope `drill`, kind `local` / `drive` / `r2`. |
| `app/Services/BackupRestorer.php` | `backup:restore`'s engine: `prepare($file, $offsite)` (file / path / newest R2 or Drive copy, decrypts `.enc`, takes the dump out of a `.zip`), `check()`, `rehearse()` (scratch-database try via the restore test), `restore()` = safety copy (`prerestore`), maintenance mode, wipe, `loadDump()`, table-count check, migrate, caches, back online; rolls the safety copy back when the load fails; copies only `storage/app/public` + `storage/app/private` from an archive (zip-slip and links refused, nothing deleted); file lock against two restores; MySQL/MariaDB only. Every database / machine step is a small protected method for tests. |
| `app/Services/BackupRestoreException.php` | A restore that failed after changing the database: `rolledBack` true / false / null and the `safetyFile`. |
| `app/Services/BackupHealth.php` | Is each backup fresh? Checks (hourly, daily, one "Copy on …" per off-site place with Auto upload on, restore test) from `backup_runs` against `backup.health` limits; states ok / warning / danger / unknown and `overall()`. Used by the dashboard card, `backup:check` and `/health/backups`. |
| `app/Services/BackupEncryptor.php` | Streams a file through AES-256-CBC in the `openssl enc -pbkdf2 -iter 100000 -md sha256` format (decryptable with plain `openssl`); key `BACKUP_ENCRYPTION_KEY` (not `APP_KEY`); wrong key / truncated / foreign files are refused and leave no output. |
| `app/Services/AppBackupService.php` | Full app zip `app-backup-{date}-{kind}.zip` (files + a database backup of the same kind, made first), written to `.part` then renamed, per-kind `prune()`, optional encrypted off-site copy (`OffsiteBackupService`); records an app run. |
| `app/Services/BackupFiles.php` | Deletes stale `.part` leftovers of killed backups from a backup folder. |
| `app/Services/AppReleaseStateService.php` | Tracks release versions, deployment tags, and migration readiness. |
| `app/Services/AppUpdateService.php` | Manages application update checks, Git pulls, and cache refreshing. |
| `app/Services/AppUpdatePushService.php` | Dispatches deployment update notifications to administrators. |
| `app/Services/CoolifyDeploymentService.php` | Interacts with Coolify webhook APIs to trigger container redeployments. |
| `app/Services/DeploymentErrorReporter.php` | Captures, deduplicates, and logs critical module and migration exceptions. |
| `app/Services/LoginGate.php` | Evaluates login requests against brute-force rules before executing bcrypt checks; at a `CHALLENGE` a solved CAPTCHA token clears it. |
| `app/Services/TurnstileVerifier.php` | Checks a Cloudflare Turnstile token with `siteverify`; any failure counts as "not solved". |
| `app/Support/LoginCaptchaSettings.php` | Global CAPTCHA switch and Turnstile keys (encrypted `AppSetting`), set on Settings → Login Security (`app/Filament/Pages/LoginSecuritySettings.php`, super admin). |
| `app/Filament/Concerns/HasLoginCaptcha.php` | The CAPTCHA step shared by the admin/office, reseller and investor sign-in pages (view `filament/components/login-captcha`). |
| `app/Services/LicenseActivationService.php` | Verifies and stores system software license keys and activation metadata. |
| `app/Services/AuditLogService.php` | Records administrative actions and data modifications to the audit log. |

### Support
| Path | What it does |
| --- | --- |
| `app/Support/ModuleGuard.php` | Wraps component execution to catch exceptions and prevent 500 errors in panels. |
| `app/Support/ModuleHealth.php` | Health check probes evaluating database tables and service availability. |
| `app/Support/PanelErrorPages.php` | Detects panel errors and renders graceful module failure views. |
| `app/Support/GuardedViewManager.php` | Restores Laravel blade view state after an inner view component throws an error. |
| `app/Support/InfoTip.php` | Instruction text in an ⓘ icon (owner request 2026-11-01): registers `->infoTip()` on fields, infolist entries and sections (called from `AppServiceProvider`), plus `icon()`, `html()`, `heading()`; Blade component `resources/views/components/info-tip.blade.php`. Rule in `CLAUDE.md`, plan `docs/info-icon-instructions-plan.md`. |
| `app/Support/InfoTipAudit.php` | Pure-PHP tokenizer scanner that finds plain `helperText` / `description` / `hint` / `hintIconTooltip` instruction text and `->infoTip()` calls without `__()`; behind `scripts/info-tip-audit.php` and `tests/Unit/InfoTipGuardTest.php` (baseline `tests/Fixtures/info-tip-baseline.json`). |
| `app/Support/TypeToConfirmDelete.php` | "Type the name to delete" (owner request 2026-11-01): wires a name prompt (copyable name badge + input; a bulk delete asks for the number of records) into every Filament `DeleteAction` / `DeleteBulkAction` / `ForceDeleteAction` / `ForceDeleteBulkAction` from `AppServiceProvider`; `apply()` / `applyToSelection()` / `countFields()` for hand-made delete actions; `matches()`, `recordName()`, `trashUrl()`. Rule in `CLAUDE.md`, plan `docs/delete-and-trash-plan.md`. |
| `app/Filament/Resources/Pages/TrashedRecords.php` | Generic Trash page for a resource whose model uses `SoftDeletes`: the resource's own table limited to trashed rows, Restore / Delete permanently (row and bulk), and `linkAction()` for the list page's "Trash (n)" button. Used by Expenses and the Office Feed; Vouchers and Orders keep their own. |
| `app/Filament/Concerns/TrashFollowsDelete.php` | Trait for a Resource that has a Trash page: `canRestore*()` / `canForceDelete*()` follow `canDelete()` / `canDeleteAny()`. Used by Expenses and the nine T3 resources (categories, offers, carousels, reviews, slides, pages, payment methods, FAQs, quick replies). |
| `app/Filament/Concerns/HasTrashView.php` | Trait for a relation manager whose rows are soft-deleted: `showTrash` switch, "Trash (n)" header action, Restore / Delete permanently row actions. Used by the order Payments History. |
| `app/Support/AppDeployment.php` | Reads current deployment metadata, Git commit hashes, and build timestamps. |
| `app/Support/AppRelease.php` | Reads published release notes and changelog versions. |
| `app/Support/ReleaseCutter.php` | Utility script automating semantic version release tagging. |
| `app/Support/CoolifySettings.php` | Value object containing Coolify webhook URLs and deployment tokens. |
| `app/Support/LoginAttempt.php` | DTO representing the evaluation outcome of a user login attempt. |
| `app/Support/LoginClient.php` | Extracts device fingerprint, IP, and user-agent data for security gating. |
| `app/Support/LoginThrottled.php` | Represents rate-limiting and lockout states for login candidates. |
| `app/Support/CompanyScopedUnique.php` | Database validation rule asserting uniqueness scoped to `company_id`. |
| `app/Support/CompanyMedia.php` | Media resolution helper ensuring assets belong to the proper company disk. |

### Filament Providers, Clusters, Resources, Pages & Widgets
| Path | What it does |
| --- | --- |
| `app/Providers/Filament/AdminPanelProvider.php` | Configures the primary admin panel (`/admin`, ID: `admin`). |
| `app/Providers/Filament/ImsOfficePanelProvider.php` | Configures the IMS office management panel (`/office`, ID: `office`). |
| `app/Providers/Filament/InvestorPanelProvider.php` | Configures the external investor portal (`/investor`, ID: `investor`). |
| `app/Providers/Filament/ResellerPanelProvider.php` | Configures the reseller program portal (`/portal`, ID: `reseller`). |
| `app/Providers/Filament/TradePortalPanelProvider.php` | Configures the Trade Portal (`/trade`, ID: `trade`): phone-first, Bangla-first shop portal on the main domain (docs/tasneem-samples/PLAN.md). |
| `app/Filament/Trade/TradeNavigation.php` | The Trade Portal's menu: top menu on wide screens, floating bottom tab bar and the grouped Menu sheet on phones (`resources/views/filament/trade/mobile-nav.blade.php`), and `backUrl()` for the round back button in the phone header (`resources/views/filament/trade/back-button.blade.php`). |
| `app/Filament/Trade/Pages/Home.php` | Trade Portal home: greeting, today's sales (navy card), "At a glance" (dues, cash and bank, low stock, open LCs), quick jobs and short lists, from `TradePortalHome`. |
| `app/Filament/Trade/Pages/BusinessProfile.php` | Trade Portal business profile (addresses, TIN/BIN/IRC, bank accounts, signatory, letterhead); needs `trade.profile.manage`. |
| `app/Services/TradePortalCompanies.php` | Which companies a user may open in `/trade` (`trade.access` + `companies.trade_portal_enabled`), the chosen one, `mayEnter()`. |
| `app/Services/TradePortalHome.php` | The Trade Portal home figures, each card inside `ModuleGuard::run()`. |
| `app/Filament/Trade/Resources/DeliveryChallans/DeliveryChallanResource.php` | Trade Portal sales: Delivery Challan list, create (the sale), view with Print / Receive money / Cancel (`trade.sales`). Work done by `DeliveryChallanService` (sales-orders-couriers.md). |
| `app/Filament/Trade/Resources/GoodsIn/GoodsInResource.php` | Trade Portal "Goods in": short purchase entry and "Goods arrived" over `Purchase` (`trade.purchases`). |
| `app/Filament/Trade/Resources/StockItems/StockItemResource.php` | Trade Portal stock: items with stock (coming-soon cost placeholders hidden), new item with opening stock, "Correct count" (`trade.stock`). |
| `app/Filament/Trade/Resources/Dues/DueResource.php` | Trade Portal dues: customers with `current_balance > 0`, "Receive money" against a challan or the old due (`trade.dues`). |
| `app/Filament/Trade/Pages/ImportOldData.php` | Trade Portal "Bring in old data": buyers with opening dues and items with stock from CSV/Excel via `TradeImportService` (`trade.import`). |
| `app/Filament/Trade/Pages/CashBook.php` | Trade Portal cash book (`trade/cash-book`, `trade.cashbook`): day or month view and the buttons Bank deposit / move money, Pay a supplier, Other cost, Receive money (→ Dues). Data from `TradeCashBookService` (accounts-finance.md). |
| `app/Filament/Trade/Pages/BalanceSheet.php` | Trade Portal simple balance sheet (`trade/balance-sheet`, `trade.cashbook`). |
| `app/Filament/Trade/Resources/MoneyAccounts/MoneyAccountResource.php` | Trade Portal cash and bank accounts (`trade/accounts`, `trade.money_accounts`): the company's manual `Account`s with their balance; add one with its opening money. |
| `app/Filament/Trade/Resources/LcFiles/LcFileResource.php` | Trade Portal LC files (`trade/lc-files`, `trade.lc`): list with stage and bank-document status, one form saved by `LcFileService` (catalog-inventory.md), a page per file with the stages, "Move to" the next stage and the five papers (print or what is still needed). |
| `app/Filament/Trade/Resources/LcFiles/Pages/LandedCost.php` | Trade Portal landed cost worksheet of an LC file (`trade/lc-files/{record}/landed-cost`, `trade.landed_cost`): cost lines with their source, bank rate, per unit / per item, "Apply to stock buying price". Figures from `LandedCostService` (catalog-inventory.md). |
| `app/Filament/Trade/Pages/Reports.php` / `Report.php` | Trade Portal reports (`trade/reports`, `trade/report?type=&from=&to=`, `trade.reports`): tiles, then one report with its period, a table on wide screens / cards on phones, and Print, PDF, Excel buttons. |
| `app/Services/TradeReportService.php` | The nine reports (`REPORTS`: daily / monthly sales, dues by age, stock ledger, owed to suppliers, cash and bank, LC status, landed cost per shipment, shipment advice register) as one shape: columns (typed), rows, totals, notes. Reads orders + challans, customers, the stock ledger (by movement `created_at`), suppliers, the account ledger, LC files and `LandedCostService`. |
| `app/Http/Controllers/Trade/TradeReportDownloadController.php` | `GET trade/reports/{report}/download/{format}` (name `filament.trade.reports.download`): `print` = HTML print page in the portal's language (views `trade/reports/print`, browser shapes Bangla, "Save as PDF"), `pdf` = DomPDF file in English (`trade/reports/pdf`; DomPDF cannot shape Bangla), `xlsx` = OpenSpout file with numbers as numbers. Cells written by `App\Support\TradeReportFormat`. |
| `app/Filament/Trade/Resources/MachineSerials/MachineSerialResource.php` | Trade Portal serial numbers list (`trade/serials`, `trade.lc` or `trade.sales`): item, LC no., in the shop or which challan / buyer. Serials are written on the LC file page and chosen on the challan page (`MachineSerialService`). |
| `app/Http/Controllers/Trade/TradeLcDocumentController.php` | `GET trade/lc-files/{lcFile}/papers/{document}` (name `filament.trade.lc-files.document`): LC Opening Letter, Beneficiary's Certificate, Detail Packing List, Shipment Advice, DRL as A4 PDFs (views `resources/views/trade/lc/*`, DomPDF like the ERP's PI/CI/PL); a paper with empty fields returns a 422 page listing them (`LcFile::missingFor()`). |
| `app/Filament/Trade/TradeErrors.php` | Turns an ERP refusal (`ValidationException` from a service or model) inside a portal button or form into a red notice; the caller then halts so the form stays open. |
| `app/Services/TradeImportService.php` | Runs `CustomerCsvService` / `ProductCsvService` on a CSV or Excel file (`App\Support\SpreadsheetCsv`); items without SKU get `TRD-<name>`. |
| `app/Http/Controllers/Trade/TradeChallanPrintController.php` | `GET trade/challans/{challan}/print` (name `filament.trade.challans.print`): the challan on A4 like the owner's pad, view `resources/views/trade/challan-print.blade.php`. |
| `app/Filament/Clusters/CompanyManagement.php` | Navigation cluster grouping tenant company resources and settings. |
| `app/Filament/Clusters/Settings.php` | Navigation cluster grouping system-wide configuration pages. |
| `app/Filament/Resources/Companies/CompanyResource.php` | CRUD interface for managing companies, domains, and branding. |
| `app/Filament/Resources/CompanyFaqs/CompanyFaqResource.php` | Manages customer-facing FAQ items per company. |
| `app/Filament/Resources/Users/UserResource.php` | Manages staff accounts, assigned companies, roles, and status. |
| `app/Filament/Resources/UserRoles/UserRoleResource.php` | Defines permission roles and access matrices. |
| `app/Filament/Resources/DeploymentErrors/DeploymentErrorResource.php` | Displays logged deployment and module runtime exceptions for super admins (a tab on Settings → Logs). |
| `app/Filament/Resources/AuditLogs/AuditLogResource.php` | Searchable viewer for user action and data modification audit logs (a tab on Settings → Logs). |
| `app/Filament/Pages/CompanySettings.php` | Settings page for company profile, logo, timezone, currency, and address. |
| `app/Filament/Pages/CloudStorageSettings.php` | Configures S3/R2 storage disks and credentials. |
| `app/Filament/Pages/CoolifyDeploymentSettings.php` | Configures Coolify webhook deployment settings. |
| `app/Filament/Widgets/BackupHealthWidget.php` | Dashboard card "Backup health" for people who manage backups (`GuardsWidgetRendering`); one stat per `BackupHealth` check. |
| `app/Filament/Pages/Backups.php` | Backups page: Drive settings, a Cloudflare R2 status section linking to the R2 page, full app and database backup lists (with kind), Backup history from `backup_runs` (restores, restore tests, "Sent to …"). |
| `app/Filament/Pages/R2Backups.php` | R2 Backups dashboard (Settings cluster, `backups.manage`): status, stored size, free-allowance bar, estimated bill now and when the schedule has filled the bucket, health cards, bill table, growth scenarios / examples, copies by type, latest files, recent uploads; actions Refresh, Test connection, Back up now, Configure (account, bucket, folder, keys, rate). View: `resources/views/filament/pages/r2-backups.blade.php`. |
| `app/Filament/Pages/AppVersions.php` | Shows current system version, commit, and update readiness. |
| `app/Filament/Pages/ReleaseNotes.php` | Displays published software update release notes to users. |
| `app/Filament/Auth/AdminLogin.php` | Custom Filament login page integrated with `LoginGate` and company detection. |
| `app/Filament/Concerns/GuardsWidgetRendering.php` | Trait wrapping Filament widget render methods inside `ModuleGuard`. |
| `app/Filament/Concerns/HasGuardedNavigationBadge.php` | Trait ensuring navigation badge count queries never crash the navigation bar. |

### Controllers
| Path | What it does |
| --- | --- |
| `app/Http/Controllers/Admin/CompanySwitchController.php` | Handles admin switching active tenant context via session. |
| `app/Http/Controllers/Admin/AppUpgradeController.php` | Triggers background Git pull and migration deployment. |
| `app/Http/Controllers/Admin/BackupDownloadController.php` | Streams encrypted/compressed backup files to authorized super admins. |
| `app/Http/Controllers/BackupHealthController.php` | `GET /health/backups`: 200 / 503 for an outside uptime monitor, behind `BACKUP_HEALTH_TOKEN` (404 without or with a wrong token). |
| `app/Http/Controllers/Admin/LegacyAdminClusterRedirectController.php` | Redirects legacy unclustered URLs to their new Filament cluster routes. |
| `app/Http/Controllers/InstallController.php` | Initial setup wizard for first-time installation and database configuration. |

### Middleware
| Path | What it does |
| --- | --- |
| `app/Http/Middleware/SetCurrentCompany.php` | Sets the request's `CompanyContext` based on user session, host, or fallback. |
| `app/Http/Middleware/RestrictPortalHosts.php` | Ensures reseller and investor portals are accessed only via authorized domains; `MAIN_HOST_PATHS` (`trade`) are served on every non-portal host. |
| `app/Http/Middleware/ResolveTradePortalCompany.php` | Trade Portal: opens the signed-in person's chosen company (or none) on every request, before route bindings. |
| `app/Http/Middleware/SetTradePortalLocale.php` | Trade Portal language: Bangla by default, English by choice (`GET trade/language/{locale}`, `Trade\TradePortalLocaleController`). |
| `app/Http/Middleware/PreventDemoModeWrites.php` | Blocks mutating HTTP requests when demo mode is active. |
| `app/Http/Middleware/SecurityHeaders.php` | Adds strict CSP, HSTS, X-Frame-Options, and nosniff HTTP response headers. |
| `app/Http/Middleware/InvalidateStaleCustomerSession.php` | Flushes outdated storefront customer session tokens upon schema or password changes. |
| `app/Http/Middleware/TurnOffPagesWithMissingTables.php` | Suppresses admin pages whose underlying migrations have not yet run. |
| `app/Http/Middleware/PreventAdminPageCaching.php` | Adds `no-cache, no-store` headers to authenticated panel routes. |
| `app/Http/Middleware/SyncAppUpdates.php` | Checks for pending application releases on incoming administrative visits. |

### Console Commands & Schedules
| Path | What it does |
| --- | --- |
| `app/Console/Commands/DeployMigrate.php` | Runs pending migrations on deploy (never fails the deploy); takes a `predeploy` database backup first when migrations are pending, a failed backup is reported and does not block. |
| `app/Console/Commands/BackupApp.php` | `backup:app --kind= --upload --auto-upload`: full app backup; `--upload` sends the encrypted copy to every place that is on, `--auto-upload` only to the places with Auto upload on and only when the kind is in `backup.offsite.app_kinds`. |
| `app/Console/Commands/BackupDatabase.php` | `backup:database --connection= --kind=hourly|daily|predeploy|manual --upload --auto-upload`; exit code 1 on failure. |
| `app/Console/Commands/DrillBackup.php` | `backup:drill --file= --offsite --r2 --drive`: the restore test; scheduled Sundays 03:30 (03:50 for the R2 copy, 04:10 for the Drive copy, each only when that place has Auto upload on) when `BACKUP_DRILL_DB_USERNAME` is set. |
| `app/Console/Commands/CheckBackupHealth.php` | `backup:check`: reports overdue checks to the Super Admins (once, then every 12 hours), exit code 1 when one is overdue; scheduled every 30 minutes. |
| `app/Console/Commands/RestoreBackup.php` | `backup:restore {file?} --offsite --r2 --drive --only=database|files --confirm= --skip-rehearsal --skip-safety-backup --no-migrate`: replaces the database (and a `.zip`'s uploaded files); asks for the database name to be typed, `--confirm=` without a keyboard. Thin over `BackupRestorer`. |
| `app/Console/Commands/DecryptBackup.php` | `backup:decrypt {file} --output= --force`: opens an off-site `.enc` copy with `BACKUP_ENCRYPTION_KEY` (or a prompted key). |
| `app/Console/Commands/VerifyDatabaseBackup.php` | `backup:verify`: SQLite restore drill in a disposable file, or completeness + checksum check of a `.sql.gz` dump. |
| `app/Console/Commands/CutRelease.php` | Automates release tagging, manifest updates, and git commit creation. |
| `app/Console/Commands/NotifyLatestRelease.php` | Alerts super admins of newly installed application updates. |
| `app/Console/Commands/MigrateCompanyData.php` | CLI tool to migrate data records across companies. |
| `app/Console/Commands/MigrateCompanyStorage.php` | CLI tool to transfer physical media between storage disks. |
| `app/Console/Commands/RemoveTradeTestData.php` | `trade:remove-test-data {company} [--prefix=TEST-] [--apply]`: removes a Trade Portal company's QA records named with the prefix and what hangs on them, via `App\Services\TradeTestDataCleanupService` (dry run by default, backup before `--apply`, refuses when test and real records are mixed, lists the ledger rows left on the money accounts). Test: `tests/Feature/TradeTestDataCleanupTest.php`. |
| `bootstrap/app.php` | Defines scheduled cron jobs (hourly `backup:database --kind=hourly --auto-upload` at :05 and daily `backup:app --kind=daily --auto-upload`, both in the background, expiring overlap locks, release notifications, cleanups). |

### Notifications & Observers
| Path | What it does |
| --- | --- |
| `app/Notifications/DeploymentErrorAlert.php` | Email and database notification dispatched when a critical deployment error occurs. |
| `app/Observers/AuditObserver.php` | Model observer logging created, updated, and deleted events to `AuditLog`. |

### Views
| Path | What it does |
| --- | --- |
| `resources/views/panel-errors/module.blade.php` | Isolated error page displayed when a single panel module encounters an error. |
| `resources/views/filament/pages/company-settings.blade.php` | Custom view template for company profile and settings. |
| `resources/views/filament/pages/cloud-storage-settings.blade.php` | Custom view template for storage configuration. |
| `resources/views/filament/pages/coolify-deployment-settings.blade.php` | Custom view template for Coolify settings. |
| `resources/views/filament/pages/backups.blade.php` | View template rendering backup listings and download triggers. |
| `resources/views/filament/pages/app-versions.blade.php` | View template showing version status and changelog cards. |
| `resources/views/filament/pages/release-notes.blade.php` | View template for user-facing release notes. |

### Config
| Path | What it does |
| --- | --- |
| `config/app.php` | Core Laravel settings, default timezone, application name, error isolation flags. |
| `config/auth.php` | Authentication guards and password reset providers for admin, customer, and portal users. |
| `config/backup.php` | Daily schedule time, `hourly.enabled` / `daily.full_app` switches, per-kind retention (`database.keep`, `app.keep`; kinds hourly/daily/predeploy/prerestore/manual), `mysqldump` binary and timeout, `restore` (mysql client, import timeout, `file_paths` copied back from an archive), app-backup paths (including `storage/app/private`), `encryption.key`, `offsite` (kinds sent, `require_encryption`, Drive `keep`), `drill` (restore test), `health` (stale limits, monitor token), Google Drive. `config/database.php` has the `backup_drill` connection. |
| `config/filesystems.php` | Disks definition (`local`, `public`, `company_media`, `s3`). |
| `config/login_protection.php` | Rates, budgets, device cookie tokens, and lockout thresholds for `LoginGate`. |
| `config/release.php` | Current release version metadata and update check endpoints. |

### Migrations (name pattern only)
- Find a table's migrations by searching `database/migrations/` for the table name: several tables are created together in one migration, so the file name does not always match the table.
- `database/migrations/*create_companies_table.php`
- `database/migrations/*create_users_table.php`
- `database/migrations/*create_user_roles_table.php`
- `database/migrations/*create_app_settings_table.php`
- `database/migrations/*create_deployment_errors_table.php`
- `database/migrations/*create_backup_runs_table.php`
- `database/migrations/*add_offsite_and_note_to_backup_runs_table.php`
- `database/migrations/*create_audit_logs_table.php`
- `database/migrations/*add_company_id_to_*`

### Tests
| Path | What it does |
| --- | --- |
| `tests/Feature/MultiCompanyIsolationTest.php` | Asserts that every company-owned model enforces `BelongsToCompany` and respects `CompanyScope`. |
| `tests/Feature/CompanySettingsTest.php` | Tests company profile saving, timezone handling, and currency configuration. |
| `tests/Feature/CompanySelectionPersistenceTest.php` | Verifies active company selection persistence across user sessions. |
| `tests/Feature/CompanyScopedUniquenessTest.php` | Tests unique constraint validation within individual tenant boundaries. |
| `tests/Feature/CompanyStorageServiceTest.php` | Verifies tenant media isolation across configured storage disks. |
| `tests/Feature/CompanyStorageMigrationTest.php` | Validates file migration between local and remote cloud disks. |
| `tests/Feature/CoolifyDeploymentServiceTest.php` | Tests webhook payloads and signature verification for Coolify deployments. |
| `tests/Feature/CoolifyDeploymentSettingsTest.php` | Tests the Coolify deployment configuration page. |
| `tests/Feature/DeploymentErrorReporterTest.php` | Tests deduplication and logging of deployment errors. |
| `tests/Feature/DeployMigrateCommandTest.php` | Tests safe execution of migrations during automated deployment, including the `predeploy` backup (taken when pending, never blocks). |
| `tests/Feature/LoginGateTest.php` | Verifies pre-hash rate limiting, device cookies, and lockout rules. |
| `tests/Feature/ModuleGuardTest.php` | Asserts that caught component exceptions return fallbacks and avoid 500 status codes. |
| `tests/Feature/BackupSystemTest.php` | Tests database dump generation and cloud upload workflows. |
| `tests/Feature/BackupEncryptionTest.php` | Tests the encryptor at every block boundary, interoperability with the real `openssl` command in both directions, wrong key / truncated / foreign files, short or missing key, `backup:decrypt`. |
| `tests/Feature/BackupDrillTest.php` | Tests the restore test: refusal without a drill user, newest / named / Drive source, incomplete or unloadable dumps recorded and alerted once, staging cleanup, wrong or missing key, the streaming into a stand-in `mysql` client, the Sunday schedules. |
| `tests/Feature/BackupRestoreTest.php` | Tests `backup:restore`: finding / decrypting / unpacking the file (name, path, `.enc`, newest Drive copy, archive), checks, the order of the steps with a safety copy, rollback when the load fails (and when that fails too), table-count check, failed migrations, an app already in maintenance mode, the lock, archive files (nothing deleted, zip-slip and links refused), the real wipe and counts on SQLite, the loader against a stand-in `mysql`, history rows, the `prerestore` kind, and the command (confirmation by typing the database name, flags, messages). |
| `tests/Feature/BackupHealthTest.php` | Tests the health checks (fresh, overdue, never succeeded, switches, Drive copy, restore test), `backup:check` alerts and reminders, `/health/backups`, the dashboard card and the history. |
| `tests/Feature/R2BackupTest.php` | Tests the R2 settings (secret encrypted, account id → endpoint, environment fallback), the service against an in-memory bucket (`tests/Support/FakeS3.php`: one request / parts / resume / abort / wrong size, listing across pages limited to the folder, download, refusal outside the folder, explained errors, connection test) and the multi-place `OffsiteBackupService` (same file to both, one place failing, all failing, Auto upload per place, manual to a set-up place, trimming, newest copy fetched and decrypted). |
| `tests/Feature/R2DashboardTest.php` | Tests the bill maths (free allowance, per GB, per million operations, taka), operations per upload, the month so far, the full-schedule forecast and scenarios, the usage report (groups, cache, errors) and the R2 Backups page (access, set-up steps and examples, figures, taka, broken connection, Configure validation, Test connection, Back up now). |
| `tests/Feature/OffsiteBackupTest.php` | Tests the auto-upload policy, the encryption guard, encrypted uploads and staging cleanup, Drive retention (only our files, per scope and kind), upload failures (run failed, alert, local prune still happens), `backup:database --upload/--auto-upload`, the pre-deploy copy, private files in the archive, the chunked upload / retry / give-up, folder listing and delete, and the encryption line on the Backups page. |
| `tests/Feature/BackupRunsTest.php` | Tests backup kinds and per-kind pruning (database and app archives), `backup_runs` recording and failure alerts, the streamed gzip MySQL dump (fake dumper + a real process), `.sql.gz` verification, `backup:database` / `backup:app` / `backup:verify`, auto-upload switches, the hourly and daily schedules and their switches, and the history on the Backups page. |
| `tests/Feature/ReleaseNotesTest.php` | Tests release notes rendering and version display. |
| `tests/Feature/ReleaseCutterTest.php` | Tests semantic release creation script. |
| `tests/Feature/ReleaseNotificationTest.php` | Tests notification dispatching when updates complete. |
| `tests/Feature/TradePortalTest.php` | Trade Portal access both ways (`/trade` vs `/admin`), host rule, company isolation, home cards, business profile. |
| `tests/Feature/TradePortalShopWorkTest.php` | Trade Portal pages: challan form + print, receive money, goods in, stock, dues, CSV/Excel import, permissions. |
| `tests/Feature/DeliveryChallanServiceTest.php` | Delivery Challan rules against the owner's paper (totals, stock, due, payments, cancel, numbering). |
| `tests/Feature/TradeReportsTest.php` | Trade Portal reports: each report's figures, screen / print / PDF / Excel for all nine, Bangla print vs English PDF, Excel numbers, period changes, permission, company isolation. |
| `tests/Feature/TradeLandedCostTest.php` | Trade Portal landed cost worksheet (rate, lines, sharing, apply, no effect on purchase/supplier) and machine serial numbers (purchase limits, challan, cancel), with their pages. |
| `tests/Feature/TradeLcFileTest.php` | Trade Portal LC file: one form to purchase/supplier/file, the five papers' wording against the samples, PDF output, missing-field gate (cover note), stages, permissions, isolation. |
| `tests/Feature/TradePortalCashBookTest.php` | Trade Portal cash book, balance sheet and cash/bank accounts pages, permissions, refusals shown as notices. |
| `tests/Feature/UserManagementEscalationTest.php` | Ensures non-super admins cannot elevate privileges or modify protected users. |

## Routes and panels
- **Admin Panel**: URL prefix `admin`, provider: `App\Providers\Filament\AdminPanelProvider`, panel ID `admin`. Default system panel.
- **IMS Office Panel**: URL prefix `office`, provider: `App\Providers\Filament\ImsOfficePanelProvider`, panel ID `office`. Dedicated investment back-office.
- **Investor Portal**: URL prefix `investor`, provider: `App\Providers\Filament\InvestorPanelProvider`, panel ID `investor`. Public investor self-service portal.
- **Reseller Portal**: URL prefix `portal`, provider: `App\Providers\Filament\ResellerPanelProvider`, panel ID `reseller`. Reseller management portal.
- **Trade Portal**: URL prefix `trade`, provider: `App\Providers\Filament\TradePortalPanelProvider`, panel ID `trade`. Shop portal for a company with `trade_portal_enabled`; guard `web`; routes `GET trade/language/{locale}` (name `filament.trade.language`) and `GET trade/challans/{challan}/print` (name `filament.trade.challans.print`); resources at `trade/challans`, `trade/goods-in`, `trade/stock`, `trade/dues`, `trade/accounts`, `trade/lc-files` (+ `/{record}/landed-cost`), `trade/serials`; pages `trade/reports`, `trade/report`; route `GET trade/reports/{report}/download/{format}`; route `GET trade/lc-files/{lcFile}/papers/{document}`; pages `trade/import`, `trade/business-profile`, `trade/cash-book`, `trade/balance-sheet`.
- **Core HTTP Routes**:
  - `POST admin/company/switch` (name `admin.company.switch`) -> `App\Http\Controllers\Admin\CompanySwitchController`
  - `POST admin/app-updates/sync` (name `admin.app-updates.sync`) -> `App\Http\Controllers\Admin\AppUpgradeController@synchronize`
  - `POST admin/app-upgrade` (name `admin.app-upgrade`) -> `App\Http\Controllers\Admin\AppUpgradeController@upgrade`
  - `GET|HEAD admin/backups/download/{filename}` (name `backups.download`) -> `App\Http\Controllers\Admin\BackupDownloadController`
  - `GET|HEAD health/backups` (name `health.backups`) -> `App\Http\Controllers\BackupHealthController`
  - `GET|HEAD install` (name `install.create`) -> `App\Http\Controllers\InstallController@create`
  - `POST install` (name `install.store`) -> `App\Http\Controllers\InstallController@store`

## Data
- `companies`: Primary tenant table. Key columns: `id`, `name`, `slug`, `business_type`, `logo`, `phone`, `email`, `address`, `currency`, `timezone`, `invoice_prefix`, `is_active`, `settings` (JSON), `domain`, `domain_verified`, `dashboard_color`, `reseller_module_enabled`, `reseller_portal_domain`, `reseller_subdomains_enabled`, `reseller_custom_domains_enabled`, `investor_portal_enabled`, `investor_portal_domain`, `investor_project_completed_message`, `ims_enabled`, `ims_domain`, `bin_number` (import papers), `irc_number`, `tin_number`, `signatory_name`, `signatory_title`, `signature_path`, `trade_portal_enabled`, `registered_address`, `factory_address`, `challan_bin_number` (Delivery Challan; blank = `bin_number`, `Company::challanBin()`), `bank_accounts` (JSON list), `letterhead_path`, `created_at`, `updated_at`.
- `company_user`: Pivot table associating users with accessible companies. Key columns: `id`, `company_id`, `user_id`, `role`, `is_default`, `created_at`, `updated_at`.
- `users`: Staff and administrator accounts. Key columns: `id`, `name`, `email`, `email_verified_at`, `password`, `remember_token`, `role`, `is_active`, `acknowledged_app_deployment_id`, `app_upgrade_acknowledged_at`, `acknowledged_app_version`, `acknowledged_app_commit`, `acknowledged_app_built_at`, `last_company_selection`, `created_at`, `updated_at`.
- `user_roles`: Custom role permissions table. Key columns: `id`, `name`, `slug`, `permissions` (JSON), `is_active`, `created_at`, `updated_at`.
- `app_settings`: Global key-value store. Key columns: `id`, `key` (unique), `value`, `is_encrypted`, `created_at`, `updated_at`.
- `deployment_errors`: Module and deployment crash tracker. Key columns: `id`, `source`, `message`, `details`, `context` (JSON), `occurred_at`, `created_at`, `updated_at`.
- `backup_runs`: Backup attempts and restore tests (global). Key columns: `id`, `scope` (database|app|drill), `kind` (hourly|daily|predeploy|manual; for a drill local|drive), `status` (running|success|failed), `filename`, `size_bytes`, `sha256`, `error`, `note`, `started_at`, `finished_at`, `offsite_at`, `created_at`, `updated_at`.
- `audit_logs`: User activity and data mutation audit log. Key columns: `id`, `company_id`, `user_id`, `action`, `auditable_type`, `auditable_id`, `old_values` (JSON), `new_values` (JSON), `ip_address`, `user_agent`, `created_at`, `updated_at`.

## Rules that matter
- **Tenant Isolation Enforcement**: Every company-owned model MUST use the `BelongsToCompany` trait and register `CompanyScope`. In queries, if the active `CompanyContext` is denied (`none`), queries fail-closed with `1 = 0`. Enforced by `app/Scopes/CompanyScope.php` and `app/Models/Concerns/BelongsToCompany.php`.
- **Middleware Precedence for Route Binding**: `SetCurrentCompany` middleware is prepended before `SubstituteBindings` in `bootstrap/app.php` so that route-model-binding resolution queries are constrained to the tenant. Enforced by `bootstrap/app.php`.
- **Super Admin Protection**: An admin cannot delete or deactivate their own user account, and at least one active `super_admin` must remain in the database at all times. Enforced by `app/Models/User.php`.
- **Panel Access**: `User::canAccessPanel()` — `office` via `ImsOfficeCompanies::mayEnter()`, `trade` via `TradePortalCompanies::mayEnter()`, `admin` for every active user except a role whose permissions are all `trade.*` (`isTradePortalOnly()`, e.g. the editable `trade_owner` role); any other panel is refused (the investor and reseller portals use their own guards). Tested by `tests/Feature/TradePortalTest.php`.
- **Permission Checking Hierarchy**: Super admins (`super_admin`) bypass permission gates with wildcard access (`*`). All other users must have the explicit permission slug present in their role's JSON permission array. Enforced by `app/Models/User.php`.
- **Module Failure Isolation**: Navigation badges, widget rendering, and layout hooks must run inside `ModuleGuard::run` or `renderView` so a database or runtime crash inside one module does not bring down the entire admin panel. Production isolates; local/testing environments rethrow by default unless `ISOLATE_MODULE_ERRORS=true`. Enforced by `app/Support/ModuleGuard.php`.
- **Login Gate Protection**: All authentication attempts must be evaluated by `LoginGate::begin` prior to invoking bcrypt hashes to thwart DDoS credential stuffing. Enforced by `app/Services/LoginGate.php` and `app/Filament/Auth/AdminLogin.php`.
- **Software License Key Format**: License keys must match the regex `^ZZERP-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$`. Enforced by `app/Services/LicenseActivationService.php`.
- **Documentation Discrepancy (Filament Version)**: Legacy documents (e.g. `PROJECT_GUIDE.md`) refer to Filament 4; the actual codebase runs Filament 5 (`app/Providers/Filament/AdminPanelProvider.php`).
- **Backups**: a backup's kind is the last part of its file name (`BackupRun::kindOfFile()`); each kind is pruned on its own (`config/backup.php` `database.keep` / `app.keep`), and a file with no kind counts as `manual`. The daily database dump is the one inside the daily full app backup. Only the copy sent to Google Drive is encrypted (`OffsiteBackupService`); the files on the server are not. Nothing is uploaded unencrypted unless `BACKUP_OFFSITE_REQUIRE_ENCRYPTION=false`, and Drive cleanup only ever deletes files named like our backups. Backup freshness is judged by `BackupHealth` from `backup_runs`; the scheduler cannot watch itself, so `/health/backups` is meant for an outside monitor. The restore test only ever creates and drops schemas matching `backup_drill_{14 digits}_{6 chars}`. A backup is only listed once complete (streamed to `.part`, renamed after mysqldump's "Dump completed" line). Recording in `backup_runs` must never stop a backup (the table may not exist yet before `migrate`). Enforced by `DatabaseBackupService` and `BackupRunRecorder`.

## Links to other modules
- **catalog-inventory.md**: All catalog and inventory models rely on `BelongsToCompany` and `CompanyScope`.
- **sales-orders-couriers.md**: Customer, Order, and Courier models enforce multi-company isolation via `CompanyContext`.
- **accounts-finance.md**: Accounts, Vouchers, and Transaction Ledgers are strictly scoped by `CompanyScope`.
- **storefront-blade.md**: Storefront resolution uses domain and path detection in `SetCurrentCompany` and `ResolveCompanyFromDomain`.
- **reseller.md**: Reseller domain routing and customer authentication hook into `RestrictPortalHosts` and `SetCurrentCompany`.
- **investments-ims.md**: IMS Office panel access and permissions are defined in `User::canAccessPanel` and `Company::forImsHost`.
- **ai-marketing-tools.md**: AI generation settings and API tokens leverage `AppSetting` and `CompanySettingsService`.

## Related docs
- `docs/roles-and-permissions.md` — Detailed breakdown of user roles and permission matrices.
- `docs/deployment.md` — Coolify deployment guides and environment configuration.
- `docs/login-protection.md` — Architectural design and tuning for `LoginGate` and brute-force defenses.
- `docs/backup-restore.md` — Database backup workflows, schedules, Google Drive off-site copies with encryption, and restore steps.
- `docs/release-policy.md` — Semantic versioning and release cutting policies.
- `docs/update-safety.md` — Guidelines for error isolation and schema migration safety.
- `03_STACK_UPGRADE_PLAN.md` — Architecture plan for multi-company isolation and framework upgrades.
- `09_DASHBOARD_WOOCOMMERCE_SECURITY_PLAN.md` — Defensive error handling and exception page architecture.
