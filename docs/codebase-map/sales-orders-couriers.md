# Sales, Orders & Couriers
> Last checked: 2026-10-24 (the order email twin of the status SMS, and the Email the customer button; before it: main merged with the Trade Portal's Delivery Challan)

## What it does
The Sales, Orders & Couriers module orchestrates customer acquisition, order fulfillment, multi-carrier parcel delivery, and delivery fraud prevention. It manages the full lifecycle of orders from draft quotations to confirmed, processing, delivered, or returned states, while tracking payments and stock reservations. It integrates with major Bangladeshi courier logistics APIs (Steadfast, Pathao, RedX, eCourier, and manual delivery), automating airway bill booking, webhook tracking, parcel barcode scanning, and driver handover sheets. For fraud mitigation, it runs an intelligent customer risk scoring engine that evaluates historical courier return ratios, first-time high COD thresholds, phone number reuse, and a dual-tier (global and tenant-scoped) customer blacklist.

## Where to start
- `app/Models/Order.php` — Primary sales order model with sequential numbering, soft deletes, and status-driven stock reservation flags.
- `app/Services/OrderWorkflowService.php` — Manages order status transitions, cancellation rollbacks, and payment status recalculation.
- `app/Services/CourierManager.php` — Courier driver gateway resolving configured providers (Steadfast, Pathao, RedX, eCourier, Manual).
- `app/Services/CustomerRiskService.php` — Evaluates customer trust scores (0–100) and flags delivery risk factors.
- `app/Models/CustomerBlacklist.php` — Dual-tier customer blacklist supporting both tenant-scoped and global (cross-company) blocks.
- `app/Filament/Resources/Orders/OrderResource.php` — Filament admin interface for managing sales orders, line items, and courier bookings.
- `app/Filament/Pages/ScanParcels.php` — Barcode scanning interface for warehouse fulfillment and parcel verification.
- `app/Http/Controllers/CourierWebhookController.php` — Entry point for carrier tracking and delivery status webhooks.

## Files

### Models
| Path | What it does |
| --- | --- |
| `app/Models/Order.php` | Main sales order entity tracking items, total, shipping, courier booking, and payment status. |
| `app/Models/OrderItem.php` | Line items attached to an order, recording unit price, variant ID, and quantity. |
| `app/Models/OrderCost.php` | Operational costs associated with fulfilling an order (packaging, ad allocation, handling). |
| `app/Models/OrderPayment.php` | Payments received against an order (COD, advance gateway payment, bank deposit). |
| `app/Models/OrderStatusTransition.php` | Audit trail of order status changes, recording previous/next status and user ID. |
| `app/Models/Quotation.php` | Price quotation estimates with validity expiry and conversion to confirmed orders. |
| `app/Models/QuotationItem.php` | Items attached to a price quotation. |
| `app/Models/Customer.php` | Customer profiles recording contact details, delivery addresses, and lifetime order values. |
| `app/Models/CustomerPayment.php` | General customer payment receipts credited towards outstanding account dues. |
| `app/Models/CustomerBlacklist.php` | Fraud prevention blacklist matching phone, email, address, or IP across companies. |
| `app/Models/CustomerRiskProfile.php` | Cached customer risk score, delivery success ratios, and return history. |
| `app/Models/CustomerRiskEvent.php` | Logged risk occurrences (e.g. repeated cancellations, address mismatch, high COD). |
| `app/Models/CustomerRiskReview.php` | Staff review decisions approving or flagging high-risk customers. |
| `app/Models/CourierProvider.php` | Configured courier API credentials and account settings per company. |
| `app/Models/CourierBooking.php` | Courier consignment record storing consignment ID, tracking code, status, and fee. |
| `app/Models/CourierReturn.php` | Tracks returned parcels received back from couriers and reason for return. |
| `app/Models/CourierStatusLog.php` | Historical timeline of status events received from courier webhooks or polls. |
| `app/Models/CourierWebhookLog.php` | Raw HTTP payload log of incoming webhook events from logistics carriers. |
| `app/Models/DeliveryChallan.php` | The Trade Portal's sale paper (pad SL no., buyer as written, advance / cash / cheque / due terms, signer); the sale itself is its `order` (`Order::SOURCE_TRADE_CHALLAN`). |
| `app/Models/DeliveryChallanItem.php` | A challan line: description, quantity, unit, optional price and product, remarks. |
| `app/Models/ChatOrderLink.php` | Secure one-time links sent to customers via chat to complete checkout. |

### Services
| Path | What it does |
| --- | --- |
| `app/Services/OrderWorkflowService.php` | Coordinates order status flows, stock reservation checks, and payment reconciliations. |
| `app/Services/OrderStatusWorkflowService.php` | Enforces valid status transitions and triggers SMS notifications on stage changes. Processing / Shipping orders may also go back to an earlier open stage (`Order::stepBackWorkflowStages()`); a step back resets the delivery state, leaves the courier booking alone and sends no customer message (`OrderStatusTransition::isStepBack()`). |
| `app/Services/OrderStockCheckService.php` | Checks inventory levels before confirming an order to prevent overselling. |
| `app/Services/OrderSummaryCardService.php` | Builds the shareable order summary card data (logo, contact, order and customer details, items with pictures, totals, payment state); the browser draws it on the order page. |
| `app/Services/OrderSummaryCardPdfService.php` | The summary card as a one-page PDF: the card picture (as tall as its products need) plus a link over the courier tracking button; the code is not shown. Opened from the order page (`orders.summary-card.pdf`). |
| `app/Services/ShortShipmentService.php` | "Fewer pieces sent": per-line pieces really sent, the new courier amount (current minus the price of the pieces left out), cuts the order's quantities in one transaction and records how the customer confirmed. |
| `app/Services/OrderSummaryCardImageService.php` | Draws the same card as a PNG on the server (Imagick + Pango, Bangla-safe) for the automatic WhatsApp card. |
| `app/Services/OrderActivityFeedService.php` | Combines audit logs, status changes, and courier events into a unified order timeline. |
| `app/Services/OrderStatusSmsService.php` | Texts the customer when an order is created (`order.placed`) and at each new stage, using the owner's SMS Templates wording. |
| `app/Services/OrderStatusEmailService.php` | The same for email (Settings → Email Templates): `Order::created` and `OrderStatusTransition::created` queue `SendOrderEmailJob` only when that stage has a written template. |
| `app/Services/OrderWhatsAppCardService.php` | Sends the summary card picture on WhatsApp (official Cloud API, approved image-header template) on order creation and each stage change. |
| `app/Services/OrderWhatsAppSettings.php` | Company settings for the WhatsApp order card (on/off, channel, template), stored in `companies.settings`. |
| `app/Services/ShippingFeeService.php` | Calculates inside/outside Dhaka shipping fees based on customer address and weight. |
| `app/Services/CustomerRiskService.php` | Calculates risk scores from return ratios, duplicate orders, and blacklist hits. |
| `app/Services/CustomerRiskSettingsService.php` | Manages risk factor thresholds and score deduction weights. |
| `app/Services/CustomerAccountService.php` | Calculates customer balance dues and payment summaries. |
| `app/Services/CustomerOrderSummaryService.php` | The customer page's numbers: accounted orders (confirmed / processing / completed), total purchased, paid on orders + the customer's own payments, current due, first and last order date. |
| `app/Services/CustomerCsvService.php` | Exports customer contact databases and processes CSV imports. |
| `app/Services/CustomerDueAlertService.php` | Identifies customers with overdue payment balances and triggers alerts. |
| `app/Services/CourierManager.php` | Factory resolving carrier adapter instances based on provider configurations. |
| `app/Services/CourierService.php` | Business service executing consignment creation, cancellation, and fee balance syncing. `codMismatch()` / `recordCodChange()`: the booking's COD against what the order now needs, shown as a red box on the order page. |
| `app/Services/CourierAlertService.php` | Raises alerts for stuck shipments, delayed deliveries, and abnormal return rates. |
| `app/Services/DeliveryChallanService.php` | Trade Portal Delivery Challan: one transaction makes the `DeliveryChallan` (+ items), an order with `source = trade_challan` (completed, delivered, no shipping fee), order lines for product lines (stock leaves; short stock refused by item name) and the advance / cash paid as `OrderPayment`s; `receivePayment()`, `receiveOldDue()` (a `CustomerPayment`), `cancel()` (order cancelled, stock back), `nextNumber()` (pad SL no.). Order-status SMS skip `trade_challan` orders. |
| `app/Services/NuportOrderImportService.php` | Copies past orders out of a Nuport CSV export (orders, lines, payments, status, sales channel, returned pieces, finished courier bookings) without touching stock or accounts; run from the Orders list or `orders:import-nuport`. Orders still open in Nuport can come in as live orders (`Order::SOURCE_NUPORT_OPEN`) that take stock. |
| `app/Services/CourierReportService.php` | Aggregates courier delivery performance, success rates, and COD settlement totals. |
| `app/Services/CourierHandoverSheetService.php` | Generates printable handover manifests with barcodes for carrier pickup. |
| `app/Services/ExternalCourierFraudService.php` | Queries third-party courier fraud databases (Pathao, Steadfast, RedX) by phone number. |
| `app/Services/Couriers/AbstractCourierAdapter.php` | Base contract defining standard courier API methods. |
| `app/Services/Couriers/SteadfastCourierAdapter.php` | Steadfast Courier API driver implementation. |
| `app/Services/Couriers/PathaoCourierAdapter.php` | Pathao Courier API driver implementation. |
| `app/Services/Couriers/RedxCourierAdapter.php` | RedX Logistics API driver implementation. |
| `app/Services/Couriers/ECourierAdapter.php` | eCourier API driver implementation. |
| `app/Services/Couriers/ManualCourierAdapter.php` | Adapter for internal delivery staff or manual local messengers. |
| `app/Services/SteadfastCourierClient.php` | Low-level HTTP client for Steadfast courier endpoints. |
| `app/Services/PathaoCourierClient.php` | Low-level HTTP client with OAuth token caching for Pathao endpoints. |
| `app/Services/RedxCourierClient.php` | Low-level HTTP client for RedX logistics endpoints. |
| `app/Services/ECourierClient.php` | Low-level HTTP client for eCourier endpoints. |
| `app/Services/CourierFraud/CourierFraudClient.php` | Shared interface for third-party courier fraud and delivery ratio checks. |
| `app/Services/CourierFraud/PathaoFraudClient.php` | Queries Pathao historical delivery performance for a phone number. |
| `app/Services/CourierFraud/RedxFraudClient.php` | Queries RedX parcel delivery success records for a customer. |
| `app/Services/CourierFraud/SteadfastFraudClient.php` | Queries Steadfast delivery history and return rates. |

### Filament Clusters, Resources, Pages & Widgets
| Path | What it does |
| --- | --- |
| `app/Filament/Clusters/Sales.php` | Navigation cluster grouping orders, quotations, customers, and payments. |
| `app/Filament/Clusters/Courier.php` | Navigation cluster grouping courier bookings, providers, returns, and reports. |
| `app/Filament/Resources/Orders/OrderResource.php` | Primary sales order management resource with filters for stages and payment statuses. |
| `app/Filament/Resources/Orders/Pages/ListOrders.php` | Tabbed order listing (All, Confirmed, Processing, Completed, Cancelled, Returned). |
| `app/Filament/Resources/Orders/Pages/CreateOrder.php` | Form for entering customer details, selecting line items, and setting shipping. Opened with `?customer=ID` (CRM → Email's "Create order") it picks that active customer of the company and runs the customer field's own after-update (name, shipping fee, courier check). |
| `app/Filament/Resources/Orders/Pages/EditOrder.php` | Edit page for adjusting order items, courier booking, and operational costs. |
| `app/Filament/Resources/Orders/Pages/ViewOrder.php` | Infolist view with activity feed, fraud checks, and summary KPI cards. |
| `app/Filament/Resources/Orders/Actions/ChangeOrderWorkflowAction.php` | Quick action dropdown to advance order workflow stages. |
| `app/Filament/Resources/Orders/Actions/EmailCustomerAction.php` | "Email the customer" on the order page: mails one of the company's own email templates to the customer, with the order's values filled in. |
| `app/Filament/Resources/Orders/Actions/CourierBookingFeedback.php` | Modal action to create an airway bill booking with the selected courier. |
| `app/Filament/Resources/Orders/Actions/PutReturnedGoodsBackInStockAction.php` | Action allowing returned goods to be inspected and restored to inventory. |
| `app/Filament/Resources/Quotations/QuotationResource.php` | Manages sales quotations and conversion into orders. |
| `app/Filament/Resources/Customers/CustomerResource.php` | Customer directory, risk badges, address book, and order histories. |
| `app/Filament/Resources/Customers/Widgets/CustomerPurchaseSummaryWidget.php` | The four cards on the customer page: Orders, Total purchased, Total paid, Current due. |
| `app/Filament/Resources/Customers/RelationManagers/OrdersRelationManager.php` | Order History on the customer page (read-only): date, invoice, items, stage, money, newest first. The order page's customer name links here (`OrderInfolist::customerUrl()`). |
| `app/Filament/Resources/CustomerPayments/CustomerPaymentResource.php` | Logs customer payments and allocates credits across orders. |
| `app/Filament/Resources/CustomerBlacklists/CustomerBlacklistResource.php` | Manages global and company-specific blacklist entries (Super Admin only). |
| `app/Filament/Resources/CustomerRiskProfiles/CustomerRiskProfileResource.php` | Displays computed customer risk scores and warning flags. |
| `app/Filament/Resources/CustomerRiskEvents/CustomerRiskEventResource.php` | Audit table of logged risk occurrences. |
| `app/Filament/Resources/CustomerRiskReviews/CustomerRiskReviewResource.php` | Interface for manual risk assessment reviews. |
| `app/Filament/Resources/CourierProviders/CourierProviderResource.php` | Manages carrier API keys, secret tokens, and pickup store IDs. |
| `app/Filament/Resources/CourierBookings/CourierBookingResource.php` | Searchable listing of all carrier bookings and live tracking statuses. |
| `app/Filament/Resources/CourierReturns/CourierReturnResource.php` | Tracks parcels marked returned by couriers awaiting warehouse check-in. |
| `app/Filament/Resources/CourierStatusLogs/CourierStatusLogResource.php` | Timeline log of external courier status changes. |
| `app/Filament/Resources/CourierWebhookLogs/CourierWebhookLogResource.php` | Log viewer for inspecting incoming courier webhook HTTP requests. |
| `app/Filament/Pages/FraudChecker.php` | Interactive tool to check any phone number across courier fraud APIs and blacklists. |
| `app/Filament/Pages/ScanParcels.php` | Warehouse barcode scanning station for packing and dispatch verification. |
| `app/Filament/Pages/CourierHandoverSheet.php` | Generates and prints daily carrier parcel handover sheets. |
| `app/Filament/Pages/CourierMerchantDashboard.php` | Performance dashboard analyzing courier delivery rates, delays, and costs. |
| `app/Filament/Pages/CourierPaymentHistory.php` | Tracks COD payment disbursements and settlement reconciliations from couriers. |
| `app/Filament/Pages/CustomerRiskSettings.php` | Settings page for configuring risk scoring deduction weights and thresholds. |
| `app/Filament/Widgets/CourierHealthWidget.php` | Dashboard widget showing active bookings, delivered rates, and return percentages. |
| `app/Filament/Widgets/CustomerRiskOverview.php` | Widget summarizing low, medium, and high-risk customer breakdowns. |
| `app/Filament/Widgets/CustomerRiskAlerts.php` | Alerts staff to orders placed by high-risk or blacklisted customers. |
| `app/Filament/Widgets/CustomerDueNotifications.php` | Highlights customers with overdue credit balances. |
| `app/Filament/Concerns/ChecksCourierFraud.php` | Reusable trait adding fraud check badges and actions to customer and order forms. |

### Support
| Path | What it does |
| --- | --- |
| `app/Support/FraudCheckReport.php` | DTO encapsulating third-party courier fraud check responses and success ratios. |
| `app/Support/Code128.php` | Barcode generation utility creating SVG Code128 barcodes for parcel labels. |

### Controllers
| Path | What it does |
| --- | --- |
| `app/Http/Controllers/Admin/OrderPdfController.php` | Invokable controller generating printable PDF invoices for single orders. |
| `app/Http/Controllers/Admin/CustomerCsvController.php` | Handles customer CSV data exports and bulk imports. |
| `app/Http/Controllers/CourierHandoverSheetController.php` | Renders the printable HTML handover sheet for carrier driver signoff. |
| `app/Http/Controllers/CourierWebhookController.php` | Unauthenticated public webhook endpoint receiving carrier tracking updates. |
| `app/Http/Controllers/QuotationPublicController.php` | Public customer-facing view for reviewing and accepting quotations. |
| `app/Http/Controllers/ChatOrderController.php` | Renders chat-initiated order checkout forms for customers. |

### Jobs, Commands & Schedule
| Path | What it does |
| --- | --- |
| `app/Jobs/CheckExternalCourierFraudJob.php` | Background job fetching delivery success ratios from external courier APIs. |
| `app/Jobs/ProcessCourierWebhook.php` | Background job updating courier booking status and order status from webhook data. |
| `app/Jobs/SendOrderStatusSmsJob.php` | Queued job dispatching order status update SMS messages to customers. |
| `app/Jobs/SendOrderPlacedSmsJob.php` | Queued (delayed) job texting the customer a new order's details. |
| `app/Jobs/SendOrderWhatsAppCardJob.php` | Queued job sending the order summary card on WhatsApp. |
| `app/Console/Commands/ImportNuportOrders.php` | `orders:import-nuport {file} {company-slug}`: previews or applies a Nuport order import from the terminal. |
| `app/Console/Commands/SyncCourierStatuses.php` | Cron job polling courier APIs for live parcel tracking updates. |
| `app/Console/Commands/MarkExpiredQuotations.php` | Nightly cron job expiring quotations that have exceeded their valid date. |

### Views
| Path | What it does |
| --- | --- |
| `resources/views/courier/handover-sheet.blade.php` | Printable carrier dispatch handover sheet with barcodes and package list. |
| `resources/views/filament/pages/courier-handover-sheet.blade.php` | Filament page wrapper for generating handover sheets. |
| `resources/views/filament/pages/courier-merchant-dashboard.blade.php` | View template for courier analytics and delivery stats. |
| `resources/views/filament/pages/courier-payment-history.blade.php` | View template for COD payment settlements. |
| `resources/views/filament/pages/scan-parcels.blade.php` | Barcode scanning UI for parcel dispatch. |
| `resources/views/orders/pdf.blade.php` | Standard single-order PDF invoice template. |
| `resources/views/orders/print.blade.php` | Printable browser invoice layout. |
| `resources/views/orders/print-bulk.blade.php` | Bulk batch thermal printer layout for packing slips. |
| `resources/views/quotations/public.blade.php` | Public client view for estimates and quotations. |

### Migrations (name pattern only)
- Find a table's migrations by searching `database/migrations/` for the table name: several tables are created together in one migration, so the file name does not always match the table.
- `database/migrations/*create_customers_table.php`
- `database/migrations/*create_orders_table.php`
- `database/migrations/*create_order_items_table.php`
- `database/migrations/*create_order_payments_table.php`
- `database/migrations/*create_courier_returns_table.php`
- `database/migrations/*create_customer_risk_*`

### Tests
| Path | What it does |
| --- | --- |
| `tests/Feature/SalesOrderTest.php` | Tests order creation, total calculations, and line item persistence. |
| `tests/Feature/OrderStatusWorkflowTest.php` | Tests order stage progression, transitions, and stock reservation. |
| `tests/Feature/OrderStepBackTest.php` | Tests going back to an earlier stage: which stages, delivery state reset, courier booking untouched, no customer message, scan stays forward-only. |
| `tests/Feature/CourierCodMismatchTest.php` | Tests the courier-amount check after booking and recording the new amount. |
| `tests/Feature/ShortShipmentTest.php` | Tests fewer pieces sent: the new amount, the order and stock, refusals. |
| `tests/Feature/OrderSummaryCardPdfTest.php` | Tests the PDF card: tracking button instead of the code, page size, link, taller with more products. |
| `tests/Feature/InvoiceEmptyFieldsTest.php` | Tests that empty invoice fields are hidden and filled ones shown. |
| `tests/Feature/CustomerOrderHistoryTest.php` | Tests the customer page's money numbers, order history list and the order page's customer link. |
| `tests/Feature/CourierIntegrationTest.php` | Tests courier booking creation, airway bill generation, and webhooks. |
| `tests/Feature/DeliveryChallanServiceTest.php` | Tests the Delivery Challan against the owner's paper: totals, stock, due, later payments, cancel, numbering, old due. |
| `tests/Feature/NuportOrderImportTest.php` | Tests the Nuport order import: mapping, statuses, SKU mapping, sales channel, returned pieces, courier bookings. |
| `tests/Feature/CourierHandoverSheetTest.php` | Tests handover sheet manifest filtering and printable output. |
| `tests/Feature/CustomerRiskTest.php` | Tests risk scoring algorithm, return ratio deduction, and blacklist matching. |
| `tests/Feature/FraudCheckerPageTest.php` | Tests live phone check queries against external fraud adapters. |
| `tests/Feature/ScanParcelsTest.php` | Tests barcode scanning parcel verification and state updates. |
| `tests/Feature/QuotationTest.php` | Tests quotation issuance, expiry commands, and order conversion. |

## Routes and panels
- **Panels**:
  - `admin` panel:
    - Sales Cluster (`admin/sales/*`): Orders, Customers, Customer Payments.
    - Courier Cluster (`admin/courier/*`): Courier Bookings, Courier Providers, Courier Returns, Courier Status Logs, Courier Webhook Logs, Scan Parcels, Handover Sheet, Merchant Dashboard, Payment History, Customer Blacklists, Customer Risk Profiles, Customer Risk Events, Customer Risk Reviews, Customer Risk Settings, Fraud Checker.
    - CRM Cluster (`admin/crm/*`): Quotations.
- **Admin & Public HTTP Routes**:
  - `GET|HEAD admin/orders/{order}/print` (name `orders.print`) -> `Closure`
  - `GET|HEAD admin/orders/print-bulk` (name `orders.print.bulk`) -> `Closure`
  - `GET|HEAD admin/orders/{order}/pdf` (name `orders.pdf`) -> `App\Http\Controllers\Admin\OrderPdfController`
  - `GET|HEAD admin/courier-handover-sheet` (name `courier.handover-sheet`) -> `App\Http\Controllers\CourierHandoverSheetController`
  - `GET|HEAD quotation/{quotationNumber}` (name `quotation.public`) -> `App\Http\Controllers\QuotationPublicController@show`
  - `GET|HEAD o/{token}` (name `chat-order.show`) -> `App\Http\Controllers\ChatOrderController@show`
  - `POST o/{token}` (name `chat-order.store`) -> `App\Http\Controllers\ChatOrderController@store`
  - `POST webhooks/couriers/{provider}` (name `couriers.webhook`) -> `App\Http\Controllers\CourierWebhookController`
  - `GET|HEAD admin/customers/export/csv` (name `customers.export.csv`) -> `App\Http\Controllers\Admin\CustomerCsvController@export`
  - `GET|HEAD admin/customers/import/sample` (name `customers.import.sample`) -> `App\Http\Controllers\Admin\CustomerCsvController@sample`

## Data
- `orders`: Core sales order records. Key columns: `id`, `company_id`, `customer_id`, `order_number`, `status` (`pending`, `processing`, `completed`, `cancelled`), `delivery_status`, `order_date`, `subtotal`, `shipping_fee`, `shipping_zone`, `discount`, `vat`, `total_amount`, `paid_amount`, `due_amount`, `source`, `sales_channel`, `courier_provider_id`, `packaging_fee`, `external_reference`, `deleted_at`, `created_at`, `updated_at`. Adding, changing or deleting an `OrderPayment` re-syncs the order's paid/due and the customer's `current_balance` (`Order::recalculatePaidAmount()`).
- `delivery_challans`: Trade Portal challans. Key columns: `id`, `company_id`, `order_id`, `customer_id`, `challan_number` (unique per company), `challan_date`, `buyer_name`, `buyer_phone`, `buyer_address`, `total`, `advance_amount`, `advance_date`, `advance_note`, `cash_amount`, `cash_date`, `payment_account_id`, `cheque_number`, `cheque_bank`, `due_terms`, `authorised_name`, `authorised_designation`, `authorised_phone`, `note`, `status` (`confirmed`, `cancelled`), `cancelled_at`, `cancel_reason`, `created_by`.
- `delivery_challan_items`: `id`, `company_id`, `delivery_challan_id`, `product_id`, `description`, `quantity`, `unit`, `unit_price`, `remarks`, `sort_order`.
- `order_items`: Order line items. Key columns: `id`, `company_id`, `order_id`, `product_id`, `product_variant_id`, `variant_label`, `quantity`, `returned_quantity`, `unit_price`, `subtotal`, `unit_cost`, `reseller_base_price`, `reseller_retail_price`, `is_upsell`, `created_at`, `updated_at`.
- `customers`: Customer registry. Key columns: `id`, `company_id`, `name`, `phone`, `email`, `address`, `opening_balance`, `current_balance`, `is_active`, `customer_type`, `customer_source`, `reseller_status`, `business_name`, `created_at`, `updated_at`.
- `customer_blacklists`: Dual-tier blacklist. Key columns: `id`, `company_id` (NULL for global across all companies), `phone`, `email`, `ip_address`, `address`, `reason`, `is_active`, `created_by`, `created_at`, `updated_at`.
- `customer_risk_profiles`: Computed risk profile. Key columns: `id`, `company_id`, `customer_id`, `phone`, `total_courier_orders`, `delivered_orders`, `returned_orders`, `cancelled_orders`, `success_ratio`, `return_ratio`, `cancel_ratio`, `risk_score`, `risk_level`, `is_blacklisted`, `factors` (JSON), `evaluated_at`, `created_at`, `updated_at`.
- `courier_providers`: Courier API configuration. Key columns: `id`, `company_id`, `name`, `slug`, `driver`, `credentials` (JSON), `settings` (JSON), `is_active`, `is_default`, `sync_failure_count`, `last_sync_error`, `last_synced_at`, `created_at`, `updated_at`.
- `courier_bookings`: Logistics airway bills. Key columns: `id`, `company_id`, `courier_provider_id`, `order_id`, `tracking_id`, `provider_reference`, `recipient_name`, `recipient_phone`, `recipient_address`, `cod_amount`, `status`, `delivery_fee_charged`, `delivery_cost`, `cod_charge_amount`, `margin`, `booked_at`, `delivered_at`, `returned_at`, `note`, `last_synced_at`, `created_at`, `updated_at`.
- `courier_returns`: Returned parcels registry. Key columns: `id`, `company_id`, `courier_provider_id`, `courier_booking_id`, `provider_reference`, `reason`, `status`, `requested_at`, `resolved_at`, `note`, `created_at`, `updated_at`.

## Rules that matter
- **Stock Reservation on Accounted Statuses**: Only orders in `confirmed`, `processing`, or `completed` reserve inventory and count towards customer dues. Moving an order to `cancelled` or `returned` releases stock reservations. Enforced by `app/Models/Order.php` and `app/Services/OrderWorkflowService.php`.
- **Courier COD = the due amount**: every booking (popup, bulk action, API) asks the courier to collect `Order::courierCodAmount()` = grand total (`courierInvoiceTotal()`: `total_amount`, plus the customer's zone delivery fee when the order has no shipping fee of its own) minus `paid_amount` (the Payments History ledger), never below 0; an advance of ৳200 on a ৳560 order sends ৳360, a fully paid order sends 0. The booking popups show the sum under the COD field (`CourierService::codHelperText()`); the field stays editable. The order form (`OrderForm`) and view page (`OrderInfolist`) keep the advance / paid amount, payment method and due amount in their own **Payment** section, apart from **Totals**. Covered by `CourierIntegrationTest` and `OrderFormTest`.
- **Global vs Company-Scoped Blacklist**: `CustomerBlacklist` explicitly does NOT use `BelongsToCompany` or `CompanyScope` (deliberate carve-out from `MultiCompanyIsolationTest`). A record with `company_id = NULL` is global across all companies, while a record with a company ID applies only to that company. `CustomerRiskService::blacklistMatch` queries both `whereNull('company_id')->orWhere('company_id', $companyId)`. Enforced by `app/Models/CustomerBlacklist.php` and `app/Services/CustomerRiskService.php`.
- **Fraud Risk Score Calculation**: Every customer has an evaluated risk score (0–100). Points are deducted for high courier return ratios (> threshold), low delivery success ratios, incomplete addresses (< 10 characters), recent duplicate orders within 24 hours, high COD amounts on first orders, and active blacklist hits. Enforced by `app/Services/CustomerRiskService.php`.
- **Returned Goods Restocking Separation**: Parcels returned by couriers are NOT automatically put back into available stock. Restocking requires explicit physical verification by warehouse staff using `PutReturnedGoodsBackInStockAction`, which generates a `return` stock movement. Enforced by `app/Filament/Resources/Orders/Actions/PutReturnedGoodsBackInStockAction.php`.
- **Courier Webhook Exemption & Asynchronous Processing**: Carrier webhook endpoints (`/webhooks/couriers/*`) are exempt from CSRF verification in `bootstrap/app.php`, logged in `courier_webhook_logs`, and dispatched to `ProcessCourierWebhook` for background processing to avoid timing out carrier HTTP calls. Enforced by `bootstrap/app.php` and `app/Http/Controllers/CourierWebhookController.php`.
- **Scheduled Status Polling**: Scheduled command `couriers:sync-statuses` runs every 30 minutes to fetch tracking updates for in-transit shipments where webhooks may have failed. Enforced by `bootstrap/app.php` and `app/Console/Commands/SyncCourierStatuses.php`.

## Links to other modules
- **core-platform.md**: All models except `CustomerBlacklist` use `BelongsToCompany` and are scoped by `CompanyScope`.
- **catalog-inventory.md**: Orders check stock via `OrderStockCheckService`, and returned items create stock movements via `PutReturnedGoodsBackInStockAction`.
- **accounts-finance.md**: Order payments create credit vouchers, and courier COD settlement receipts create accounting ledger entries.
- **storefront-blade.md**: Online checkouts create orders in `Order` and invoke `CustomerRiskService` to flag fraudulent orders.
- **api-integrations.md**: Next.js storefront API and WooCommerce order synchronization push and pull order status changes through `OrderStatusWorkflowService`.
- **reseller.md**: Resellers place wholesale orders through `ResellerOrderPlacementService`, with custom reseller profit margins and own-courier options.

## Related docs
- `02_LEAD_CRM_MODULE_PLAN.md` — CRM lead generation and conversion to sales orders.
- `docs/roles-and-permissions.md` — Explains sales staff permissions (`sales.view`, `sales.create`, `sales.update`).
- `docs/screenshots/orders-create.png` — Visual reference for the admin order creation interface.
