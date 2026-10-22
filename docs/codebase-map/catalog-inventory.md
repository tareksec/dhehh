# Catalog & Inventory
> Last checked: 2026-10-21, merge of `claude/purchase-module-automation` into main (PI / CI / Packing List, trade document columns on companies, suppliers, products, purchases and purchase items)

## What it does
The Catalog & Inventory module manages products, variants, categories, digital media assets, and stock lifecycle across warehouses and companies. It implements an immutable stock movement ledger where on-hand quantities are strictly computed from transactional movements (opening, purchase, sale, return, damage, adjustment, and internal transfers). It provides multi-company physical inventory sharing through Stock Pools, allowing different companies to sell from the same physical stock without inventory drift. For purchasing and supply chain operations, it tracks suppliers, import purchase orders with detailed China-to-BD landed costs, shipping containers, and customs tracking. Additionally, it provides batch operations for bulk stock updates, product setup onboarding, and CSV import/export capabilities.

## Where to start
- `app/Models/Product.php` — Core product model supporting simple and variant products, tier pricing, and preorder flags.
- `app/Models/StockMovement.php` — Ledger entry recording quantity adjustments, sales, returns, and damages with company scoping.
- `app/Services/StockMovementService.php` — Business service calculating signed stock totals, synchronizing pooled inventory, and enforcing damage rules.
- `app/Models/StockPool.php` — Cross-company inventory bridge linking multiple product rows to a single physical stock source.
- `app/Models/Purchase.php` — Import purchase tracking international shipping expenses, LC/PI references, and unit cost breakdowns.
- `app/Filament/Resources/Products/ProductResource.php` — Primary Filament resource for managing products, prices, and variant attributes.
- `app/Filament/Resources/StockPools/StockPoolResource.php` — Admin interface for configuring cross-company shared inventory pools.
- `app/Services/ProductCsvService.php` — CSV importer and exporter for catalog listings and stock levels.

## Files

### Models
| Path | What it does |
| --- | --- |
| `app/Models/Product.php` | Product entity with pricing tiers, MOQ, preorder settings, and company scoping. |
| `app/Models/ProductVariant.php` | Product variant entity storing distinct SKUs, attribute sets, and variant-level stock. |
| `app/Models/Category.php` | Hierarchical product taxonomy with company-scoped slugs and icons. |
| `app/Models/Media.php` | Central media library records tracking uploaded image files, hashes, and mime types. |
| `app/Models/StockMovement.php` | Transactional inventory movement record (sale, return, damage, transfer, adjustment). |
| `app/Models/StockPool.php` | Shared stock group linking products across companies to a physical source product. |
| `app/Models/Purchase.php` | Supplier purchase orders with China-to-BD landed freight and duty cost fields. |
| `app/Models/PurchaseItem.php` | Line items associated with a purchase order, tracking unit costs and quantities. |
| `app/Models/Container.php` | Shipping container tracking departure, arrival, and shipping line status. |
| `app/Models/Shipment.php` | Logistics shipment linking purchases to containers and transport modes. |
| `app/Models/Supplier.php` | Vendor records tracking balances, purchase history, and payment ledgers. |
| `app/Models/SupplierPayment.php` | Financial payment vouchers issued to suppliers against purchases. |
| `app/Models/ProductCarousel.php` | Curated product groupings displayed in storefront slider carousels. |
| `app/Models/ProductReview.php` | Customer product reviews with approval statuses and ratings. |

### Services
| Path | What it does |
| --- | --- |
| `app/Services/StockMovementService.php` | Computes signed stock sums, synchronizes pool balances, and prepares movements. |
| `app/Services/OrderStockCheckService.php` | Validates inventory availability during checkout and order status transitions. |
| `app/Services/LowStockAlertService.php` | Generates system notifications when inventory dips below configured reorder levels. |
| `app/Services/PurchaseWorkflowService.php` | Handles purchase receipt status transitions and automatic stock movement creation. |
| `app/Services/ProductCsvService.php` | Parses product import spreadsheets and streams CSV catalog exports. |
| `app/Services/SupplierCsvService.php` | Manages CSV import and export for supplier contact and balance data. |
| `app/Services/SupplierReportService.php` | Aggregates purchase totals, payments, and outstanding balances for supplier PDF reports. |
| `app/Services/ProductSetupService.php` | Manages system installation state, onboarding status, and demo mode flags. |

### Filament Clusters, Resources, Pages & Widgets
| Path | What it does |
| --- | --- |
| `app/Filament/Clusters/Inventory.php` | Navigation cluster for products, categories, stock ledgers, and media hub. |
| `app/Filament/Clusters/Purchasing.php` | Navigation cluster for purchases, suppliers, shipments, and containers. |
| `app/Filament/Resources/Products/ProductResource.php` | Filament resource for catalog management, pricing, and variants. |
| `app/Filament/Resources/Products/Pages/ListProducts.php` | Product listing table with search, category filtering, and export actions. |
| `app/Filament/Resources/Products/Pages/CreateProduct.php` | Create form for new simple or variable catalog items. |
| `app/Filament/Resources/Products/Pages/EditProduct.php` | Edit page for product data, variant matrices, and media. |
| `app/Filament/Resources/Products/Pages/ViewProduct.php` | Infolist page displaying product specifications and stock summaries. |
| `app/Filament/Resources/Products/Pages/BulkUpdateStock.php` | Bulk inventory update tool allowing rapid tabular stock level adjustments. |
| `app/Filament/Resources/Products/Widgets/ProductStatsOverview.php` | Dashboard widget showing total catalog count, out-of-stock items, and low-stock counts. |
| `app/Filament/Resources/Categories/CategoryResource.php` | Category taxonomy management with hierarchy and icon selection. |
| `app/Filament/Resources/StockMovements/StockMovementResource.php` | Stock movement ledger resource for manual adjustments and historical audit. |
| `app/Filament/Resources/StockPools/StockPoolResource.php` | Resource for creating and managing cross-company shared inventory pools. |
| `app/Filament/Resources/StockPools/Pages/BulkLinkStockPools.php` | Tool for rapidly linking corresponding products into stock pools. |
| `app/Filament/Resources/Media/MediaResource.php` | Central Media Hub resource for asset browsing, tags, and reuse. |
| `app/Filament/Resources/Purchases/PurchaseResource.php` | Purchase order management, landed cost entry, and receipt workflows. |
| `app/Filament/Resources/Suppliers/SupplierResource.php` | Supplier profile management, phone, address, and balance ledgers. |
| `app/Filament/Resources/SupplierPayments/SupplierPaymentResource.php` | Records payment disbursements made to suppliers. |
| `app/Filament/Resources/Containers/ContainerResource.php` | Shipping container registry tracking tracking numbers and arrival dates. |
| `app/Filament/Resources/Shipments/ShipmentResource.php` | Shipment tracking linking containers and purchase consignments. |
| `app/Filament/Pages/ProductSetup.php` | Settings wizard configuring company product defaults and demo flags. |
| `app/Filament/Pages/SupplierReport.php` | Admin page for generating supplier statements and balance overviews. |
| `app/Filament/Widgets/LowStockProducts.php` | Dashboard widget highlighting items nearing their reorder levels. |
| `app/Filament/Concerns/PersistsProductFormData.php` | Trait preserving draft product input state across form wizard steps. |
| `app/Filament/Concerns/SelectableFromMediaHub.php` | Trait allowing image pickers to pull directly from the centralized media library. |
| `app/Filament/Forms/Components/CategoryIconPicker.php` | Custom form component for selecting visual icons for categories. |

### Controllers
| Path | What it does |
| --- | --- |
| `app/Http/Controllers/Admin/ProductCsvController.php` | Handles product CSV export, blank template downloads, and bulk uploads. |
| `app/Http/Controllers/Admin/SupplierCsvController.php` | Handles supplier CSV export and template generation. |
| `app/Http/Controllers/Admin/SupplierReportPdfController.php` | Generates downloadable PDF statements for selected suppliers. |
| `app/Http/Controllers/Admin/PurchaseDocumentController.php` | `GET /admin/purchases/{purchase}/documents/{type}` (`purchases.documents`, `pi` / `ci` / `pl`): streams the Proforma Invoice, Commercial Invoice or Packing List PDF; buttons from `Purchases/Support/PurchaseDocumentActions` (numbers filled by `PurchaseWorkflowService::ensureDocumentNumber()`, amounts in words by `App\Support\NumberToWords`). |

### Console Commands
| Path | What it does |
| --- | --- |
| `app/Console/Commands/BackfillMediaHub.php` | Backfills legacy product image uploads into the centralized `Media` library. |

### Views
| Path | What it does |
| --- | --- |
| `resources/views/filament/pages/product-setup.blade.php` | Blade view for product setup and onboarding settings. |
| `resources/views/filament/pages/supplier-report.blade.php` | Blade view for generating supplier financial reports. |
| `resources/views/purchases/documents/{pi,ci,pl}.blade.php` | The printed trade documents (English on purpose: they go to suppliers and banks). Company trade fields (BIN/IRC/TIN, signatory) and supplier bank fields fill the buyer and seller blocks; default wording from Company Settings → Purchase Documents. |

### Migrations (name pattern only)
- Find a table's migrations by searching `database/migrations/` for the table name: several tables are created together in one migration, so the file name does not always match the table.
- `database/migrations/*create_categories_table.php`
- `database/migrations/*create_products_table.php`
- `database/migrations/*create_stock_movements_table.php`
- `database/migrations/*create_stock_pools_table.php`
- `database/migrations/*create_suppliers_table.php`
- `database/migrations/*create_purchases_table.php`
- `database/migrations/*create_purchase_items_table.php`
- `database/migrations/*create_media_table.php`

### Tests
| Path | What it does |
| --- | --- |
| `tests/Feature/ProductStockFormTest.php` | Tests product creation with initial stock and movement creation. |
| `tests/Feature/StockMovementTest.php` | Tests ledger math, movement types, and signed stock recomputations. |
| `tests/Feature/StockPoolTest.php` | Tests cross-company pool transfers and mirrored balance syncing. |
| `tests/Feature/StockPoolResourceTest.php` | Tests Filament admin resource for stock pool configuration. |
| `tests/Feature/BulkLinkStockPoolsTest.php` | Tests bulk association tool for shared inventory pools. |
| `tests/Feature/BulkUpdateStockPageTest.php` | Tests bulk tabular stock adjustments in the Filament panel. |
| `tests/Feature/ProductCsvTest.php` | Tests CSV import/export parsing and validation rules. |
| `tests/Feature/ProductStockCsvTest.php` | Tests CSV-based bulk stock adjustment workflows. |
| `tests/Feature/ProductVariantTest.php` | Tests variant generation, SKUs, and attribute matrices. |
| `tests/Feature/PurchaseTest.php` | Tests purchase creation, cost field calculations, and receipt status changes. |
| `tests/Feature/SupplierCsvTest.php` | Tests supplier CSV import and sample template downloads. |
| `tests/Feature/SupplierReportTest.php` | Tests supplier balance aggregation and PDF statement generation. |
| `tests/Feature/LowStockNotificationTest.php` | Tests low-stock alert triggers when inventory crosses reorder thresholds. |
| `tests/Feature/MediaHubTest.php` | Tests media uploads, deduplication, and attachment to catalog products. |

## Routes and panels
- **Panels**:
  - `admin` panel:
    - Inventory Cluster (`admin/inventory/*`): Products, Categories, Stock Movements, Stock Pools, Media Hub.
    - Purchasing Cluster (`admin/purchasing/*`): Purchases, Suppliers, Supplier Payments, Containers, Shipments.
- **Admin HTTP Routes**:
  - `GET|HEAD admin/products/export/csv` (name `products.export.csv`) -> `App\Http\Controllers\Admin\ProductCsvController@export`
  - `GET|HEAD admin/products/import/sample` (name `products.import.sample`) -> `App\Http\Controllers\Admin\ProductCsvController@sample`
  - `GET|HEAD admin/products/stock/sample` (name `products.stock.sample`) -> `App\Http\Controllers\Admin\ProductCsvController@stockSample`
  - `GET|HEAD admin/suppliers/export/csv` (name `suppliers.export.csv`) -> `App\Http\Controllers\Admin\SupplierCsvController@export`
  - `GET|HEAD admin/suppliers/import/sample` (name `suppliers.import.sample`) -> `App\Http\Controllers\Admin\SupplierCsvController@sample`
  - `GET|HEAD admin/reports/supplier/{supplier}/pdf` (name `reports.supplier.pdf`) -> `App\Http\Controllers\Admin\SupplierReportPdfController`

## Data
- `products`: Product catalog table. Key columns: `id`, `company_id`, `stock_pool_id`, `name`, `slug`, `sku`, `price`, `sale_price`, `cost_price`, `stock`, `reorder_level`, `moq`, `has_variants`, `is_active`, `category_id`, `barcode`, `unit`, `brand`, `vat_rate`, `status`, `gallery_images` (JSON), `variant_attributes` (JSON), `tier_prices` (JSON), `is_preorder`, `preorder_advance_percent`, `weight_kg`, `video_path`, `created_at`, `updated_at`.
- `product_variants`: Variant SKU table. Key columns: `id`, `company_id`, `product_id`, `sku`, `options` (JSON), `cost_price`, `sale_price`, `stock`, `images` (JSON), `is_active`, `sort_order`, `weight_kg`, `reseller_suggested_price`, `created_at`, `updated_at`.
- `categories`: Category taxonomy. Key columns: `id`, `company_id`, `name`, `slug`, `description`, `is_active`, `image`, `icon`, `tagline`, `created_at`, `updated_at`.
- `media`: Centralized media library. Key columns: `id`, `company_id`, `path`, `original_filename`, `mime_type`, `size`, `uploaded_by`, `created_at`, `updated_at`.
- `stock_movements`: Immutable inventory movement ledger. Key columns: `id`, `company_id`, `product_id`, `product_variant_id`, `type`, `quantity`, `reference_type`, `reference_id`, `note`, `reason`, `damage_source`, `created_at`, `updated_at`.
- `stock_pools`: Cross-tenant inventory pool. Key columns: `id`, `source_product_id`, `created_at`, `updated_at`.
- `suppliers`: Vendor registry. Key columns: `id`, `company_id`, `name`, `phone`, `email`, `address`, `company_name`, `opening_balance`, `current_balance`, `is_active`, `created_at`, `updated_at`.
- `purchases`: Purchase orders and shipments. Key columns: `id`, `company_id`, `supplier_id`, `purchase_number`, `purchase_date`, `subtotal`, `discount`, `vat`, `total_amount`, `paid_amount`, `due_amount`, `status`, `update_cost_price`, `note`, `custom_costs` (JSON), `funding_sources` (JSON), `product_costs` (JSON), `created_at`, `updated_at`.
- `purchase_items`: Purchase line items. Key columns: `id`, `company_id`, `purchase_id`, `product_id`, `quantity`, `unit_cost`, `subtotal`, `allocated_cost`, `landed_unit_cost`, `short_quantity`, `damaged_quantity`, `created_at`, `updated_at`.
- `containers`: Shipping containers. Key columns: `id`, `company_id`, `container_number`, `shipping_line`, `origin`, `destination`, `status`, `estimated_departure`, `actual_departure`, `estimated_arrival`, `actual_arrival`, `notes`, `created_at`, `updated_at`.
- `shipments`: Freight consignments. Key columns: `id`, `company_id`, `container_id`, `purchase_id`, `tracking_number`, `carrier`, `transport_mode`, `status`, `shipped_at`, `estimated_arrival`, `received_at`, `notes`, `ims_delay_alerted_at`, `created_at`, `updated_at`.

## Rules that matter
- **Stock Derived from Ledger**: Product `stock` is never edited directly; it is derived and synchronized strictly from the `stock_movements` ledger via `StockMovementService`. Enforced by `app/Services/StockMovementService.php` and `app/Models/StockMovement.php`.
- **Cross-Company Stock Pooling**: `StockPool` models intentionally do NOT use `BelongsToCompany` or `CompanyScope` (deliberate carve-out in `MultiCompanyIsolationTest`). A pool links products from different companies to one `source_product_id`. When a pooled product moves stock, `StockMovementService::maybeCreatePoolTransfer` automatically generates equal-and-opposite `transfer` movements to keep all member companies in sync without changing pool net totals. Enforced by `app/Models/StockPool.php` and `app/Services/StockMovementService.php`.
- **Damage Source Mandate**: A stock movement of type `damage` must provide a valid `damage_source` (`customer_return`, `shipment`, or `other`). Non-damage movements must have `damage_source = null`. Enforced by `app/Services/StockMovementService.php`.
- **Variant vs Simple Stock Isolation**: For products where `has_variants = true`, stock is maintained and tracked per `product_variant_id`. Stock pooling is strictly restricted to simple products. Enforced by `app/Models/Product.php` and `app/Filament/Resources/StockPools/StockPoolResource.php`.
- **Shipment Tenant Alignment**: When assigning a `container_id` or `purchase_id` to a shipment, both the container and purchase must belong to the exact same `company_id` as the shipment. Enforced by `app/Models/Shipment.php`.
- **Landed Cost Breakdown**: China-to-BD import purchases track distinct cost fields (`machine_purchase`, `freight_to_ctg`, `duty`, `c_and_f`, `truck`, `load_unload`, etc.) which are factored into unit landed costs upon receiving the purchase. Enforced by `app/Models/Purchase.php` and `app/Services/PurchaseWorkflowService.php`.

## Links to other modules
- **core-platform.md**: All models except `StockPool` use `BelongsToCompany` and respect `CompanyScope`.
- **sales-orders-couriers.md**: Sales order creation checks inventory availability via `OrderStockCheckService`, and order fulfillment/returns create `StockMovement` records via `PutReturnedGoodsBackInStockAction`.
- **accounts-finance.md**: Supplier purchases and supplier payments generate financial vouchers and affect cash accounts.
- **storefront-blade.md**: Storefront catalog rendering, price displays, preorder flags, and stock validation query `Product` and `Category`.
- **api-integrations.md**: Website API routes export product listings, and WooCommerce sync updates product stock and prices.
- **reseller.md**: Reseller catalog links wholesale prices to `Product` and overrides customer-facing pricing.

## Related docs
- `docs/import-export.md` — Guide for CSV import and export templates for products and suppliers.
- `01_INVESTOR_MUDARABAH_MODULE_PLAN.md` — Explains landed cost structures and purchase-linked investor capital.
- `07_OFFERS_COMBO_LANDING_PAGE_PLAN.md` — Specifications for product bundle pricing and promotional reviews.
