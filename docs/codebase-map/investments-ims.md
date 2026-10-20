# Investments & IMS v2
> Last checked: 2026-10-20, feature/ims-all-companies (plan 18 phases A-D: every company on the one IMS domain, office company picker, All companies page, investor projects from every company)

## What it does
The Investments & IMS v2 module manages Islamic Mudarabah investment partnerships, syndicated investor pools, commercial project funding, and comprehensive investor relations. Built natively into the application following the retirement of the legacy external IMS, it provides two dedicated Filament 5 panels: the IMS Office back-office panel (`/office`) for investment managers, and the Investor Portal (`/investor`) for self-service investor access. It automates investor onboarding with NID OCR verification, digital agreement acceptance, syndicated investor group lifecycles, and automated daily profit proposals. Additionally, it features an investor transparency feed, scheduled monthly statement generation, capital return queues, bank statement reconciliation, and channel partner commission settlements.

## Where to start
- `app/Providers/Filament/ImsOfficePanelProvider.php` — Panel configuration for the `/office` IMS back-office (ID: `office`).
- `app/Providers/Filament/InvestorPanelProvider.php` — Panel configuration for the `/investor` investor self-service portal (ID: `investor`).
- `app/Ims/Capital/CapitalCalculator.php` — Core financial engine calculating invested capital, group shares, and return balances.
- `app/Ims/Profit/ProposalService.php` — Generates automated daily profit allocation proposals from completed project sales.
- `app/Services/Investment/SettlementService.php` — Calculates project settlement figures according to Mudarabah profit-sharing ratios (PSR).
- `app/Filament/Ims/Office/Pages/Home.php` — IMS Office dashboard displaying daily money cards, maturing pools, and owner to-do tasks.
- `app/Filament/Investor/Pages/Dashboard.php` — Investor portal home screen detailing total active capital, earned returns, and project updates.
- `app/Ims/Kyc/NidOcr.php` — OCR parser extracting national ID numbers and biographical data from uploaded identity cards.

## Files

### Models
| Path | What it does |
| --- | --- |
| `app/Models/Investor.php` | Investor profile storing identity details, tax IDs, KYC status, and cumulative balances. |
| `app/Models/InvestmentProject.php` | Commercial import/trading project funded by investor capital. |
| `app/Models/Investment.php` | Individual capital placement record linking an investor to a project or syndicated group. |
| `app/Models/InvestorGroup.php` | Syndicated investment pool with predefined cycle duration, target capital, and status. |
| `app/Models/InvestorGroupMember.php` | Membership record tracking an investor's fractional ownership units in a group. |
| `app/Models/ProjectSettlement.php` | Financial settlement closing a project, calculating net profit and manager shares. |
| `app/Models/SettlementPayout.php` | Individual profit disbursement line allocated to an investor upon project settlement. |
| `app/Models/PayoutBatch.php` | Batch grouping of approved payouts for bulk bank transfer execution. |
| `app/Models/PayoutBatchItem.php` | Individual payout line item inside an approved payout batch. |
| `app/Models/ChannelPartner.php` | Marketing affiliate or partner referring investors in exchange for commission. |
| `app/Models/ChannelPartnerPayout.php` | Commission payment record disbursed to a channel partner. |
| `app/Models/AgreementTemplate.php` | Legal agreement contract template with dynamic placeholder tokens. |
| `app/Models/AgreementAcceptance.php` | Audit record of an investor signing a digital agreement with timestamp and IP. |
| `app/Models/InvestorRequest.php` | Inquiries submitted by investors (capital withdrawal notices, reinvestment requests). |
| `app/Models/InvestorDraft.php` | Staged investor registration awaiting administrative review and KYC approval. |
| `app/Models/InvestorPortalAccount.php` | Authentication credentials for investors logging into `/investor`. |
| `app/Models/InvestorInvite.php` | Unique invitation tokens generated to invite new investors to the portal. |
| `app/Models/InvestorKycDocument.php` | Scanned NID cards, passports, and utility bills uploaded for identity verification. |
| `app/Models/InvestorNominee.php` | Registered beneficiary/nominee contact details and percentage allocations per investor. |
| `app/Models/InvestmentDocument.php` | Legal instruments, trade licenses, and certificates attached to projects. |
| `app/Models/InvestmentWithdrawalNotice.php` | Formal 30/60-day notice submitted by an investor to withdraw matured capital. |
| `app/Models/InvestmentWitness.php` | Witness records signed on physical or digital investment contracts. |

### Services & Domain Classes (`app/Ims/` & `app/Services/`)
| Path | What it does |
| --- | --- |
| `app/Ims/Capital/CapitalCalculator.php` | Calculates live investor capital balances, accrued profits, and deduction totals. |
| `app/Ims/Capital/CapitalReturnService.php` | Processes matured capital repayments and transfers back to investor bank accounts. |
| `app/Ims/Capital/GroupCapitalSync.php` | Keeps total pool balances synchronized as members join or withdraw capital. |
| `app/Ims/Capital/MaturityQueue.php` | Identifies investment groups reaching maturity dates and alerts management. |
| `app/Ims/Feed/FeedService.php` | Manages transparency newsfeed posts, photos, comments, and project progress updates. |
| `app/Ims/Files/ImsFiles.php` | Manages secure storage and access policies for private KYC and investment documents. |
| `app/Ims/Investments/MoneyConfirmationService.php` | Validates bank deposit proofs and confirms capital receipt into company accounts. |
| `app/Ims/Kyc/KycService.php` | Coordinates identity verification workflow and status transitions. |
| `app/Ims/Kyc/NidOcr.php` | OCR extraction engine parsing text from scanned Bangladeshi NID cards. |
| `app/Ims/Notifications/ImsNotifier.php` | Dispatches SMS, email, and portal alerts for profit credits and statements. |
| `app/Ims/Office/AllCompaniesFigures.php` | Plan 18 §3.3: the All companies page's figures, read company by company under its own context and added up per currency. |
| `app/Ims/Office/OfficeFigures.php` | Aggregates portfolio KPIs (total assets under management, payout obligations) for the office. |
| `app/Ims/Onboarding/InviteService.php` | Generates secure registration tokens and handles onboarding link acceptance. |
| `app/Ims/Profit/ProposalService.php` | Compiles daily profit distribution proposals from completed project sales. |
| `app/Ims/Profit/ProjectFinancials.php` | Analyzes project revenues against landed costs to determine gross profit margins. |
| `app/Ims/Reports/ImsPdf.php` | PDF generation wrapper for monthly investor account statements. |
| `app/Ims/Reports/ProjectReportService.php` | Generates comprehensive project performance and settlement PDF reports. |
| `app/Ims/Reports/StatementService.php` | Compiles monthly financial statements detailing capital, returns, and withdrawals. |
| `app/Ims/Settings/ImsSettings.php` | Value object containing company IMS preferences, domains, and notification toggles. |
| `app/Ims/Support/SupportService.php` | Handles customer support tickets and live chat between investors and managers. |
| `app/Ims/Transparency/ProjectUpdateService.php` | Publishes photographic updates and milestones for ongoing projects. |
| `app/Services/Investment/SettlementService.php` | Calculates Mudarabah profit shares and executes project settlement disbursements. |
| `app/Services/Investment/PayoutProofService.php` | Attaches banking transaction receipts to completed payout batches. |
| `app/Services/Investment/InvestmentWithdrawalService.php` | Coordinates formal capital withdrawal requests and ledger deductions. |
| `app/Services/InvestorPortalService.php` | General portal business service resolving company contexts and dashboards. |
| `app/Services/InvestorPortalAccountService.php` | Manages investor portal credentials, password updates, and phone changes. |
| `app/Services/ImsOfficeCompanies.php` | Plan 18 §3.1–3.2: which companies a staff member may open in the office (Super Admin / `ims.all_companies` = every company, else their own), the chosen company (session `ims_office_company_id`, link `?office_company=`), `mayEnter()` for `User::canAccessPanel('office')`. |
| `app/Services/InvestorPortfolio.php` | Plan 18 §4: one person's companies (same phone), projects and raising-money lists from every company, home totals per currency; each read under its own company. |
| `app/Services/InvestorBusinessSwitchService.php` | One login, many businesses (plan 17 §5): the person's businesses by phone, central-host sign-in, switching (same session on the central host and on an IMS domain, one-time token between other business hosts). |
| `app/Services/AgreementService.php` | Injects investor and project variables into contract templates and generates PDFs. |

### Filament Panel Providers, Resources & Pages
| Path | What it does |
| --- | --- |
| `app/Providers/Filament/ImsOfficePanelProvider.php` | Panel configuration for the `/office` IMS back-office interface. |
| `app/Providers/Filament/InvestorPanelProvider.php` | Panel configuration for the `/investor` self-service portal. |
| `app/Filament/Clusters/Investments.php` | Admin panel cluster grouping core investment records. |
| `app/Filament/Resources/Investors/InvestorResource.php` | Admin resource for investor directory, capital balances, and KYC reviews. |
| `app/Filament/Resources/InvestmentProjects/InvestmentProjectResource.php` | Admin resource for projects, cost sheets, linked purchases, and settlements. |
| `app/Filament/Resources/InvestmentRecords/InvestmentRecordResource.php` | Admin resource for individual capital placement contracts. |
| `app/Filament/Resources/InvestorGroups/InvestorGroupResource.php` | Admin resource for managing syndicated pools, cycles, and member lists. |
| `app/Filament/Resources/InvestorDrafts/InvestorDraftResource.php` | Admin queue for reviewing new investor applications. |
| `app/Filament/Resources/InvestorRequests/InvestorRequestResource.php` | Admin queue for capital withdrawal and reinvestment requests. |
| `app/Filament/Ims/Office/Pages/Home.php` | IMS Office dashboard with daily KPIs, pending actions, and financial charts. |
| `app/Filament/Ims/Office/Pages/AllCompanies.php` | `/office/all-companies`: money, waiting items, companies, projects and new investors of every company the person may open; each line opens that company's own page. |
| `app/Filament/Ims/Office/Pages/ImsSettings.php` | Office configuration for automated schedules, notification rules, and statements. |
| `app/Filament/Ims/Office/Pages/PendingMoney.php` | Reconciliation queue for incoming bank transfers awaiting verification. |
| `app/Filament/Ims/Office/Resources/CapitalReturns/CapitalReturnResource.php` | Queue for approving and disbursing matured capital repayments. |
| `app/Filament/Ims/Office/Resources/FeedPosts/FeedPostResource.php` | Content editor for publishing project updates to the investor feed. |
| `app/Filament/Ims/Office/Resources/InvestorInvites/InvestorInviteResource.php` | Manages generated invitation links and registration tracking. |
| `app/Filament/Ims/Office/Resources/KycReviews/KycReviewResource.php` | Identity verification review screen comparing OCR data with uploaded NID cards. |
| `app/Filament/Ims/Office/Resources/ProfitProposals/ProfitProposalResource.php` | Interface for reviewing and approving daily automated profit distribution proposals. |
| `app/Filament/Ims/Office/Resources/ProjectReports/ProjectReportResource.php` | Registry of compiled project financial reports and audit summaries. |
| `app/Filament/Ims/Office/Resources/ProjectUpdates/ProjectUpdateResource.php` | Manages project milestone notices and photos. |
| `app/Filament/Ims/Office/Resources/SupportThreads/SupportThreadResource.php` | Support ticket helpdesk for investor inquiries. |
| `app/Filament/Investor/Pages/Dashboard.php` | Investor portal homepage displaying portfolio overview and recent earnings. |
| `app/Filament/Investor/Pages/MyBusinesses.php` | "My businesses": one card per business where the person's phone has an active account, with totals (shown only with more than one). |
| `app/Filament/Investor/Pages/MyInvestments.php` | Detailed listing of active and completed investments and fractional group units. |
| `app/Filament/Investor/Pages/Payouts.php` | History of received profit payouts and pending bank disbursements. |
| `app/Filament/Investor/Pages/Statement.php` | Interactive account statement viewer with monthly PDF downloads. |
| `app/Filament/Investor/Pages/Agreements.php` | Digital archive of accepted investment agreements and legal contracts. |
| `app/Filament/Investor/Pages/Requests.php` | Interface for investors to submit capital return or payout requests. |
| `app/Filament/Investor/Pages/Profile.php` | Investor profile, bank account details, and nominee management. |

### Controllers & Middleware
| Path | What it does |
| --- | --- |
| `app/Http/Middleware/ResolveCompanyFromImsDomain.php` | Binds tenant company context from custom IMS office hostnames. |
| `app/Http/Middleware/ResolveCompanyFromInvestorPortalDomain.php` | Binds tenant company context from custom investor portal hostnames; on the central sign-in host it closes the context (`none()`). |
| `app/Http/Middleware/ResolveCompanyFromCentralInvestorSession.php` | On the central sign-in host, sets the company the person chose (kept in the session); sends business-only pages to sign-in. On an IMS domain, opens the chosen business only while the signed-in account belongs to it. |
| `app/Http/Middleware/ResolveImsOfficeCompany.php` | After the session starts on `/office`: opens the signed-in staff member's chosen company (`ImsOfficeCompanies::choose()`), or closes the context when they may open none. |
| `app/Http/Middleware/OpenLinkedInvestorBusiness.php` | Investor portal, after sign-in: `?business=<id>` switches to the person's own account in that company (`switchTo()`), then reopens the page without it. |
| `app/Http/Middleware/RedirectInvestmentsToImsOffice.php` | Redirects staff visiting legacy investment URLs to the modern `/office` panel. |
| `app/Http/Middleware/SetImsOfficeLocale.php` | Sets session locale (Bangla/English) for the IMS Office panel. |
| `app/Http/Middleware/SetInvestorPortalLocale.php` | Sets session locale (Bangla/English) for the Investor Portal. |
| `app/Http/Controllers/Ims/ImsOfficeFileController.php` | Securely serves bank statement imports and proof documents to office staff. |
| `app/Http/Controllers/Ims/ImsOfficeLocaleController.php` | Switches interface language between English and Bangla in the office panel. |
| `app/Http/Controllers/Ims/ImsPortalFileController.php` | Securely streams contract documents and project attachments to investors. |
| `app/Http/Controllers/Ims/ImsPortalStatementController.php` | Downloads official monthly investment account statement PDFs. |
| `app/Http/Controllers/InvestorPortal/InvestorPortalDocumentController.php` | Serves agreement PDFs and project prospectus files. |
| `app/Http/Controllers/InvestorPortal/InvestorPortalLocaleController.php` | Switches language preference in the investor portal. |
| `app/Http/Controllers/InvestorPortal/InvestorPortalReportController.php` | Generates on-demand portfolio summary reports for logged-in investors. |
| `app/Http/Controllers/InvestorPortal/InvestorPortalStaffEntryController.php` | Allows authorized staff to preview the portal as an investor. |
| `app/Http/Controllers/InvestorPortal/InvestorBusinessSwitchController.php` | Both ends of the portal's "Switch business" button: issues the token, then signs the person in on the other business's portal. |

### Console Commands & Schedule
| Path | What it does |
| --- | --- |
| `app/Console/Commands/AdvanceInvestorGroups.php` | Scheduled hourly job rolling over investment group cycle states. |
| `app/Console/Commands/BackfillInvestorGroups.php` | One-off CLI command to backfill legacy individual investments into groups. |
| `app/Console/Commands/Ims/MakeProfitProposals.php` | Scheduled daily job (07:00) drafting profit distribution proposals from sales. |
| `app/Console/Commands/Ims/SendMaturityReminders.php` | Scheduled daily job (08:00) sending capital maturity notices to investors. |
| `app/Console/Commands/Ims/SendMonthlyStatements.php` | Scheduled monthly job (1st of month at 09:00) dispatching account statements. |
| `app/Console/Commands/Ims/SendOwnerDigest.php` | Scheduled daily job (08:05) sending portfolio performance digest to owners. |
| `app/Console/Commands/Ims/WarnLateShipments.php` | Scheduled hourly job detecting delayed project cargo and alerting managers. |

### Views
| Path | What it does |
| --- | --- |
| `resources/views/ims/pdf/layout.blade.php` | Master print layout for IMS PDF documents with bilingual headers and footers. |
| `resources/views/ims/pdf/project-report.blade.php` | Printable PDF view for project settlement and financial audit reports. |
| `resources/views/ims/pdf/statement.blade.php` | Printable monthly investor statement layout. |
| `resources/css/filament/ims/theme.css` | The shared IMS theme of both panels; colours are tokens, `:root.dark` holds the dark values (light is the default). |
| `resources/views/filament/ims/office/company-picker.blade.php` | The office's company picker: a dropdown in the top bar, a list at the top of the menu drawer on small phones. |
| `resources/views/filament/ims/auth-hero.blade.php` | Green welcome panel with three steps beside the IMS sign-in forms. |
| `resources/views/filament/ims/theme-toggle.blade.php` | Light / dark button (top bar and sign-in page), via Filament's theme store. |
| `resources/views/filament/ims/auth-tools.blade.php` | Theme + language switches above the sign-in forms. |
| `resources/views/investments/reports/investor-payout.blade.php` | Printable disbursement slip for investor profit payouts. |
| `resources/views/investments/reports/project-register.blade.php` | Formal project capital register and legal participant schedule. |

### Migrations (name pattern only)
- Find a table's migrations by searching `database/migrations/` for the table name: several tables are created together in one migration, so the file name does not always match the table.
- `database/migrations/*create_channel_partners_table.php`
- `database/migrations/*create_investor_requests_table.php`
- `database/migrations/*create_investor_drafts_table.php`

### Tests
| Path | What it does |
| --- | --- |
| `tests/Feature/Ims/CapitalTest.php` | Tests capital calculation, group sync, and return queue workflows. |
| `tests/Feature/Ims/DomainRoutingTest.php` | Tests host detection, `Company::forImsHost`, and portal host restrictions. |
| `tests/Feature/Ims/KycTest.php` | Tests NID OCR extraction, document upload, and staff review decisions. |
| `tests/Feature/Ims/OfficeHomeTest.php` | Tests IMS Office dashboard KPI calculations and todo list items. |
| `tests/Feature/Ims/OnboardingTest.php` | Tests invite token generation, OTP verification, and registration. |
| `tests/Feature/Ims/ProposalsAndJobsTest.php` | Tests daily automated profit proposal creation and scheduled jobs. |
| `tests/Feature/Ims/TransparencyTest.php` | Tests project update feed posts, comments, and investor notifications. |
| `tests/Feature/Ims/AllCompaniesImsTest.php` | Plan 18 A + B: every company on the group IMS domain, links carrying their company, the office picker and who may open which company. |
| `tests/Feature/InvestorGroupCycleTest.php` | Tests group lifecycle progression (collecting, running, maturing, settled). |
| `tests/Feature/InvestorBusinessSwitchTest.php` | Tests the business switcher: who is offered, the one-time token, staff preview and disabled accounts. |
| `tests/Feature/InvestorPortalTest.php` | Tests investor portal authentication, dashboard metrics, and statement downloads. |

## Routes and panels
- **Panels**:
  - `office` panel: URL prefix `office`, provider: `App\Providers\Filament\ImsOfficePanelProvider`, panel ID `office`. Dedicated investment back-office.
  - `investor` portal panel: URL prefix `investor`, provider: `App\Providers\Filament\InvestorPanelProvider`, panel ID `investor`. Investor self-service portal.
  - `admin` panel: Investments Cluster (`admin/investments/*`).
- **HTTP Routes**:
  - `GET|HEAD office/files/kyc/{document}` (name `filament.office.files.kyc`) -> `App\Http\Controllers\Ims\ImsOfficeFileController@kyc`
  - `GET|HEAD office/files/feed-images/{image}` (name `filament.office.files.feed-image`) -> `App\Http\Controllers\Ims\ImsOfficeFileController@feedImage`
  - `GET|HEAD office/files/update-photos/{photo}` (name `filament.office.files.update-photo`) -> `App\Http\Controllers\Ims\ImsOfficeFileController@updatePhoto`
  - `GET|HEAD office/files/capital-returns/{return}/proof` (name `filament.office.files.capital-return-proof`) -> `App\Http\Controllers\Ims\ImsOfficeFileController@capitalReturnProof`
  - `GET|HEAD office/files/project-reports/{report}/pdf` (name `filament.office.files.project-report`) -> `App\Http\Controllers\Ims\ImsOfficeFileController@projectReport`
  - `GET|HEAD office/files/projects/{project}/register` (name `filament.office.files.investments.reports.project-register`) -> `App\Http\Controllers\Admin\InvestmentReportController@projectRegister`
  - `GET|HEAD office/files/settlement-payouts/{payout}/report` (name `filament.office.files.investments.reports.investor-payout`) -> `App\Http\Controllers\Admin\InvestmentReportController@investorPayout`
  - `GET|HEAD office/language/{locale}` (name `filament.office.language`) -> `App\Http\Controllers\Ims\ImsOfficeLocaleController`
  - `GET|HEAD investor/ims/kyc/{document}` (name `filament.investor.ims.kyc`) -> `App\Http\Controllers\Ims\ImsPortalFileController@kyc`
  - `GET|HEAD investor/ims/feed-images/{image}` (name `filament.investor.ims.feed-image`) -> `App\Http\Controllers\Ims\ImsPortalFileController@feedImage`
  - `GET|HEAD investor/ims/update-photos/{photo}` (name `filament.investor.ims.update-photo`) -> `App\Http\Controllers\Ims\ImsPortalFileController@updatePhoto`
  - `GET|HEAD investor/ims/project-reports/{report}/pdf` (name `filament.investor.ims.project-report`) -> `App\Http\Controllers\Ims\ImsPortalFileController@projectReport`
  - `GET|HEAD investor/ims/statements/{period}/pdf` (name `filament.investor.ims.statement-pdf`) -> `App\Http\Controllers\Ims\ImsPortalStatementController@pdf`
  - `GET|HEAD investor/documents/{document}/download` (name `filament.investor.documents.download`) -> `App\Http\Controllers\InvestorPortal\InvestorPortalDocumentController`
  - `POST investor/switch-business/{company}` (name `filament.investor.switch-business`) -> `App\Http\Controllers\InvestorPortal\InvestorBusinessSwitchController@switch`
  - `GET|HEAD investor/business-entry/{token}` (name `filament.investor.business-entry`) -> `App\Http\Controllers\InvestorPortal\InvestorBusinessSwitchController@enter`
  - `GET|HEAD investor/payouts/{payout}/report` (name `filament.investor.reports.payout`) -> `App\Http\Controllers\InvestorPortal\InvestorPortalReportController@payout`
  - `GET|HEAD admin/investor-portal/investors/{investor}/open` (name `investor-portal.open-investor`) -> `App\Http\Controllers\Admin\InvestorPortalOpenController@investor`
  - `GET|HEAD admin/investor-portal/channel-partners/{partner}/open` (name `investor-portal.open-partner`) -> `App\Http\Controllers\Admin\InvestorPortalOpenController@partner`

## Data
- `investors`: Primary investor records. Key columns: `id`, `company_id`, `name`, `display_name`, `guardian_name`, `phone`, `email`, `address`, `nid_number`, `date_of_birth`, `channel_partner_id`, `is_channel_partner`, `nominee_name`, `nominee_nid_or_passport`, `nominee_phone`, `nominee_relation`, `nominee_address`, `stamp_number`, `cheque_number`, `kyc_status`, `kyc_submitted_at`, `kyc_reviewed_at`, `kyc_reviewed_by`, `kyc_note`, `created_at`, `updated_at`.
- `investment_projects`: Commercial investment projects. Key columns: `id`, `company_id`, `project_code`, `name`, `description`, `deal_reference`, `purchase_id`, `group_id`, `duration_type` (`2_month`, `6_month`, `12_month`, `custom_days`), `trade_cycle_days`, `start_date`, `end_date`, `target_amount`, `investor_share_percent`, `channel_partner_share_percent`, `company_share_percent`, `status` (`open`, `running`, `closed`, `settled`), `receiving_fund_source_id`, `payout_fund_source_id`, `company_contribution_amount`, `company_contribution_note`, `investment_opens_at`, `investment_closes_at`, `created_at`, `updated_at`.
- `investor_groups`: Syndicated investor pools. Key columns: `id`, `company_id`, `group_code`, `name`, `status`, `opened_on`, `running_since`, `closed_on`, `close_reason`, `cycle_months`, `auto_cycle`, `investor_share_percent`, `channel_partner_share_percent`, `company_share_percent`, `receiving_fund_source_id`, `payout_fund_source_id`, `notes`, `created_by`, `created_at`, `updated_at`.
- `investor_group_members`: Pool units. Key columns: `id`, `company_id`, `group_id`, `investor_id`, `amount`, `invested_at`, `payment_method`, `payment_reference`, `received_by`, `voucher_id`, `status`, `withdrawn_at`, `withdrawal_voucher_id`, `withdrawal_note`, `notes`, `paperwork`, `created_at`, `updated_at`.
- `investments`: Direct investment contracts. Key columns: `id`, `company_id`, `project_id`, `investor_id`, `amount`, `payment_method` (`cash`, `bkash`, `bank`, `other`), `payment_reference`, `invested_at`, `received_by`, `override_reason`, `voucher_id`, `status`, `withdrawn_at`, `withdrawal_voucher_id`, `withdrawal_note`, `group_member_id`, `created_at`, `updated_at`.
- `project_settlements`: Settlement calculations. Key columns: `id`, `company_id`, `project_id`, `total_revenue`, `total_cost`, `net_profit`, `investor_pool_amount`, `channel_partner_amount`, `company_net_amount`, `annualized_return_percent`, `status` (`confirmed`, `paid_out`), `settled_by`, `settled_at`, `rate_per_lac`, `outcome`, `loss_reason`, `basis`, `investor_loss_percent`, `locked_at`, `created_at`, `updated_at`.
- `payout_batches`: Grouped disbursements. Key columns: `id`, `company_id`, `batch_number`, `kind`, `status`, `payout_date`, `fund_source_id`, `file_format`, `total_amount`, `item_count`, `created_by`, `approved_by`, `approved_at`, `exported_at`, `export_path`, `export_sha256`, `bank_reference`, `completed_by`, `completed_at`, `cancelled_by`, `cancelled_at`, `cancel_reason`, `voucher_id`, `notes`, `created_at`, `updated_at`.

## Rules that matter
- **Native IMS Architecture & Legacy API Decommission**: The legacy external IMS data-sync API (`/api/v1/sync/*`) was permanently retired on 2026-10-19. IMS v2 is fully implemented within this monolithic repository on the shared PostgreSQL/MySQL database across the `/office` and `/investor` panels. Legacy sync routes return 404. Documented in `IMS-V2/PLAN.md` and `routes/api.php`.
- **Domain-Based Multi-Company Routing**: IMS domains are configured per tenant on `companies.ims_domain`. `ResolveCompanyFromImsDomain` resolves tenant context using `Company::forImsHost`. `RestrictPortalHosts` prevents access to storefront/admin URLs from IMS domains and vice versa. Enforced by `app/Http/Middleware/ResolveCompanyFromImsDomain.php` and `app/Http/Middleware/RestrictPortalHosts.php`.
- **Central investor sign-in host (one login, many businesses)**: `app_settings.ims_central_domain` (`Company::imsCentralHost()`, set in Investor Portal Settings) serves only `/investor`, for every company. The company comes from the session (`InvestorBusinessSwitchService::SESSION_KEY`), never from the host; with none chosen, `CompanyContext` is `none()`. The same phone with an active account in several businesses is one person. Enforced by `ResolveCompanyFromCentralInvestorSession`, `RestrictPortalHosts` and `InvestorPortalAccount::canAccessPanel()`.
- **Every company on the one IMS domain (plan 18)**: `Company::imsHost()` is the company's own IMS domain, else the group's (`Company::groupImsCompany()`: active, IMS on, domain set, lowest id), so `imsIsLive()` is true for every active company once one company runs IMS. Links to a company's own investor pages carry `?business=<id>` (`investorLinkUrl()`), office links `?office_company=<id>` (`imsOfficeUrl()`). Enforced by `app/Models/Company.php`, `InvestorBusinessSwitchService::linkCompany()` and `ResolveCompanyFromCentralInvestorSession`.
- **Office Panel Permission Gate**: Staff need `ims.office.access`; the office opens the company they chose, re-checked on every request: Super Admins and roles with `ims.all_companies` ("office admin") open every active company, others only the companies they work for. Enforced by `ImsOfficeCompanies`, `ResolveImsOfficeCompany` and `User::canAccessPanel()`.
- **Automated Profit Proposal Rules**: Scheduled job `ims:profit-proposals` runs daily at 07:00, evaluating completed order revenues against purchase landed costs to generate draft profit allocations. It uses idempotent database locks to guarantee proposals are never generated twice for the same sales period. Enforced by `bootstrap/app.php` and `app/Console/Commands/Ims/MakeProfitProposals.php`.
- **Mudarabah Shariah Principles & Ledger Invariant**: Capital placement and profit sharing strictly follow Islamic Mudarabah contracts based on the defined PSR (Profit Sharing Ratio). Capital investments and profit payouts represent balance sheet fund reallocations; per accounting rules, they NEVER generate `Expense` records. Enforced by `app/Services/Investment/SettlementService.php` and `app/Services/VoucherService.php`.
- **Identity OCR Verification Before Active Status**: Uploaded NID cards are parsed via `NidOcr` for national ID and date-of-birth extraction. An investor cannot place capital or enter investment pools until an authorized manager manually reviews and approves their KYC status via `KycReviewResource`. Enforced by `app/Ims/Kyc/NidOcr.php` and `app/Filament/Ims/Office/Resources/KycReviews/KycReviewResource.php`.

## Links to other modules
- **core-platform.md**: Multi-tenant isolation is enforced via `BelongsToCompany`, and panel permissions are defined in `User::canAccessPanel`.
- **catalog-inventory.md**: Investment projects link to import purchase consignments in `Purchase` via `LinkedPurchasesRelationManager`.
- **sales-orders-couriers.md**: Daily profit proposals generated by `ProposalService` calculate returns from completed sales orders.
- **accounts-finance.md**: Capital contributions and approved payout batches generate vouchers and update account balances via `VoucherLinkedPayoutsService`.
- **crm-messaging.md**: Investor SMS reminders and monthly statement alerts dispatch through `SmsTemplateService` and `ImsNotifier`.

## Related docs
- `IMS-V2/PLAN.md` — Strategic roadmap for IMS v2 architecture, panels, and migrations.
- `IMS-V2/TRD.md` — Technical Requirements Document detailing database schemas and scheduled jobs.
- `IMS-V2/ROLES.md` — Role and permission hierarchy for the IMS Office panel.
- `01_INVESTOR_MUDARABAH_MODULE_PLAN.md` — Islamic Mudarabah investment structure, PSR rules, and settlement logic.
- `15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md` — Implementation specifications for the investor portal.
- `16_INVESTOR_GROUPS_PLAN.md` — Syndicated investor group cycles and group rollover mechanics.
- `docs/IMS-MAJOR-SYSTEMS-VERIFIED.md` — Verification test report of all core IMS systems.
- `docs/api/ims-sync-api.md` — Historical documentation of the retired v1 sync API.
