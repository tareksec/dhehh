<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Copies orders out of Nuport's "Export As CSV" file (the wholesale order
 * dashboard the owner used before this ERP) into ERP orders, so every
 * customer's past purchases, payments and statuses are in one place.
 *
 * The file has one row per product line; the order-level money (Sales Amount,
 * Delivery Fee, Paid) is only on the first row of each invoice. Rules (owner
 * decisions, 2026-10-17):
 *
 * - The Nuport invoice (SO-1309) becomes both the order number and the
 *   external reference, so running the import again never duplicates an order:
 *   an order already imported only has its status, delivery fee, discount and
 *   payment refreshed from the newer file.
 * - Cancelled orders whose reason is "Test Order" or "Fake Order" are skipped.
 *   Every other cancelled order is imported as Cancelled, without payments.
 * - Status mapping: Delivered = Completed, Approved = Confirmed, In-Transit and
 *   Processing = Processing, Flagged (returned / pending return) = Returned,
 *   Cancelled = Cancelled. Anything else is skipped and reported, never guessed.
 * - An order is skipped as a whole when one of its products cannot be found by
 *   SKU (variant SKU first, then product SKU, then exact name for lines
 *   without a SKU); a partly imported order would misstate its totals. Fix the
 *   product, give the SKU an ERP SKU in the SKU mapping, or let the import
 *   create it (below), then import the same file again.
 * - SKU mapping (owner request, 2026-10-17): Nuport used SKUs the ERP does not
 *   have (retired or renamed ones). A mapping "NUPORT-SKU = ERP-SKU" (one per
 *   line) makes those lines use the ERP product or variant with that SKU; it
 *   wins over a direct match. A mapping whose ERP SKU does not exist is ignored
 *   and reported, never guessed.
 * - "Create missing products": a product that still cannot be found is created
 *   with the Nuport SKU (a line without a SKU gets NUPORT-<name>) and name, the
 *   last non-zero unit price as selling price, cost price 0, stock 0 and
 *   inactive, so it never shows on the website or in stock. Off by default;
 *   the preview lists what would be created.
 * - Report fields (owner request, 2026-10-20): Nuport's "Order Source" becomes
 *   the order's sales channel; "Cancelled/Returned/Damaged Qty" becomes each
 *   line's returned quantity (not for cancelled orders, which never shipped);
 *   a parcel with a consignment number whose delivery is over (delivered,
 *   partly delivered, returned, cancelled) becomes a courier booking so the
 *   courier reports and a customer's delivery history see it. A parcel still on
 *   the way is not recorded - the courier sync would poll it - and keeps its
 *   consignment number in the order note. A courier the ERP does not have is
 *   created inactive with no API, so staff cannot book with it by mistake.
 * - Imported orders never create stock movements, alerts or risk checks
 *   (Order::isHistoricalImport()), and their payments are posted with no
 *   account, so no account balance changes.
 * - Customers are matched by phone (01XXXXXXXXX, +8801XXXXXXXXX and
 *   8801XXXXXXXXX are the same person); an unknown phone creates a customer.
 * - The CSV totals win: the ERP discount is the list-price total of the lines
 *   minus Sales Amount, so product discounts and order discounts both land in
 *   it and the ERP total equals Nuport's Sales Amount plus Delivery Fee.
 */
class NuportOrderImportService
{
    /** Normalised heading keys the file must contain (see headingKey()). */
    public const REQUIRED_COLUMNS = [
        'invoice', 'customername', 'customerphonenumber', 'productqty', 'unitprice',
        'totalprice', 'salesamount', 'deliveryfee', 'paymentspaidamount', 'status', 'creationdate',
    ];

    public const STATUS_MAP = [
        'DELIVERED' => Order::STATUS_COMPLETED,
        'APPROVED' => Order::STATUS_CONFIRMED,
        'IN_TRANSIT' => Order::STATUS_PROCESSING,
        'PROCESSING' => Order::STATUS_PROCESSING,
        'FLAGGED' => Order::STATUS_RETURNED,
        'CANCELLED' => Order::STATUS_CANCELLED,
    ];

    /** Cancel reasons that mark a throw-away order (matched case-insensitively). */
    public const SKIPPED_CANCEL_REASONS = ['test order', 'fake order'];

    /**
     * Stored as the order's shipping zone so Order::creating does not replace
     * the delivery fee Nuport recorded with the zone fee for the address.
     */
    public const SHIPPING_ZONE = 'imported';

    /** Courier statuses that are final; only these become booking records (see the class note). */
    protected const BOOKING_STATUSES = [
        CourierBooking::STATUS_DELIVERED,
        CourierBooking::STATUS_PARTIAL_DELIVERED,
        CourierBooking::STATUS_RETURNED,
        CourierBooking::STATUS_CANCELLED,
    ];

    protected const PAYMENT_NOTE = 'Nuport import';

    protected const LIST_LIMIT = 15;

    protected Company $company;

    protected string $timezone;

    /** @var Collection<string, ProductVariant> */
    protected Collection $variants;

    /** @var Collection<string, Product> */
    protected Collection $productsBySku;

    /** @var Collection<string, Product> */
    protected Collection $productsByName;

    /** @var array<string, array{customer: ?Customer, new: bool}> */
    protected array $customerCache = [];

    /** @var array<string, array{from: string, to: string, label: string}> keyed by lower-case Nuport SKU; "to" is the lower-case ERP SKU, "label" the ERP SKU as typed */
    protected array $skuMap = [];

    protected bool $createMissing = false;

    /** @var Collection<int, CourierProvider>|null the company's couriers, loaded on first use */
    protected ?Collection $providers = null;

    /** True once every unit price has been seen (see run()), so planning stops changing them. */
    protected bool $pricesLocked = false;

    /** @var array<string, array{sku: string, name: string, price: float, product_id: ?int}> keyed by lower-case SKU */
    protected array $newProducts = [];

    /**
     * @param  string  $skuMap  lines of "NUPORT-SKU = ERP-SKU"
     * @param  bool  $createMissing  create (inactive) products that no SKU or mapping finds
     * @return array<string, mixed> the report (see emptyReport())
     */
    public function run(string $path, bool $dryRun = true, string $skuMap = '', bool $createMissing = false): array
    {
        $company = app(CompanyContext::class)->company();

        if (! $company) {
            throw ValidationException::withMessages(['csv' => __('Select one company before importing orders.')]);
        }

        $this->company = $company;
        $this->timezone = $company->timezone ?: (string) config('app.timezone');
        $this->customerCache = [];
        $this->createMissing = $createMissing;
        $this->newProducts = [];
        $this->providers = null;
        $this->loadProducts();

        if (! $dryRun) {
            // About 1,300 orders take a minute or more: let it finish even if
            // the browser or proxy gives up (a second run only continues).
            @set_time_limit(0);
            ignore_user_abort(true);
        }

        $invoices = $this->readInvoices($path);
        $existing = $this->existingOrders(array_keys($invoices));
        $report = $this->emptyReport(count($invoices));
        $report['dry_run'] = $dryRun;
        $this->skuMap = $this->parseSkuMap($skuMap, $report);
        $this->pricesLocked = false;

        if ($createMissing) {
            // Plan every invoice once first so a new product carries the last
            // non-zero unit price in the file, not the price of whichever
            // order happens to create it.
            foreach ($invoices as $invoice => $rows) {
                $this->plan((string) $invoice, $rows);
            }

            $this->pricesLocked = true;
        }

        foreach ($invoices as $invoice => $rows) {
            $plan = $this->plan((string) $invoice, $rows);
            $order = $existing->get((string) $invoice);

            if ($plan['skip'] === null && $order?->trashed()) {
                $plan['skip'] = 'in_trash';
            } elseif ($plan['skip'] === null && $order && $order->source !== Order::SOURCE_NUPORT_IMPORT) {
                $plan['skip'] = 'reference_in_use';
            }

            $report['ignored_lines'] += $plan['ignored_lines'];

            if ($plan['skip'] !== null) {
                $this->recordSkip($report, $plan);

                continue;
            }

            if ($plan['mismatch']) {
                $report['mismatch'][] = $plan['invoice'];
            }

            foreach (array_keys($plan['mapped']) as $from) {
                $report['mapped'][$from] ??= ['from' => $this->skuMap[$from]['from'], 'to' => $this->skuMap[$from]['label'], 'orders' => 0];
                $report['mapped'][$from]['orders']++;
            }

            $this->addStatusTotals($report, $plan);
            $this->addChannelAndBookingTotals($report, $plan);

            try {
                if ($order) {
                    $changed = $dryRun ? $this->wouldChange($order, $plan) : $this->refreshOrder($order, $plan);
                    $changed ? $report['refreshed']++ : $report['unchanged']++;

                    continue;
                }

                $customer = $this->resolveCustomer($plan['head'], $report, createMissing: ! $dryRun);

                if (! $dryRun) {
                    $this->createOrder($plan, $customer);
                }

                $report['created']++;

                foreach ($plan['lines'] as $line) {
                    if (isset($line['new'])) {
                        $new = $this->newProducts[$line['new']];
                        $report['new_products'][$new['sku']] = $new['name'];
                    }
                }
            } catch (Throwable $exception) {
                // A courier created inside the rolled-back transaction must not stay cached.
                $this->providers = null;
                $report['errors'][] = $plan['invoice'].': '.Str::limit($exception->getMessage(), 160);
            }
        }

        return $report;
    }

    /**
     * Human-readable lines for the notification and the artisan command.
     *
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    public function summary(array $report): array
    {
        $dryRun = (bool) $report['dry_run'];
        $lines = [__(':count orders in the file.', ['count' => $report['orders_in_file']])];

        $lines[] = $dryRun
            ? __(':count new orders would be imported.', ['count' => $report['created']])
            : __(':count orders were imported.', ['count' => $report['created']]);
        $lines[] = __(':count orders were imported before (:changed with a new status, fee or payment).', [
            'count' => $report['refreshed'] + $report['unchanged'],
            'changed' => $report['refreshed'],
        ]);

        foreach ($report['by_status'] as $status => $totals) {
            $lines[] = __(':status: :orders orders, sales :sales, paid :paid, due :due', [
                'status' => __(Order::STATUSES[$status] ?? $status),
                'orders' => $totals['orders'],
                'sales' => number_format($totals['sales']),
                'paid' => number_format($totals['paid']),
                'due' => number_format($totals['due']),
            ]);
        }

        $testOrFake = $report['skipped']['test_or_fake'] ?? 0;
        $unmatched = $report['skipped']['unmatched_sku'] ?? 0;
        $other = array_sum($report['skipped']) - $testOrFake - $unmatched;

        if ($testOrFake > 0) {
            $lines[] = __(':count test or fake cancelled orders were skipped.', ['count' => $testOrFake]);
        }

        if ($report['mapped'] !== []) {
            $lines[] = __('SKU mapping used: :list', [
                'list' => $this->shorten(collect($report['mapped'])
                    ->map(fn (array $map): string => $map['from'].' → '.$map['to'].' ('.$map['orders'].')')
                    ->values()
                    ->all()),
            ]);
        }

        if ($report['map_ignored'] !== []) {
            $lines[] = __('SKU mapping lines ignored (the ERP SKU was not found or the line is not understood): :list', [
                'list' => $this->shorten($report['map_ignored']),
            ]);
        }

        if ($report['new_products'] !== []) {
            $replace = [
                'count' => count($report['new_products']),
                'list' => $this->shorten(array_map(
                    fn (string $sku, string $name): string => $sku.' ('.Str::limit($name, 40).')',
                    array_keys($report['new_products']),
                    array_values($report['new_products']),
                )),
            ];

            $lines[] = $dryRun
                ? __(':count products would be created (inactive, stock 0, cost price 0): :list', $replace)
                : __(':count products were created (inactive, stock 0, cost price 0): :list', $replace);
        }

        if ($report['channels'] !== []) {
            $lines[] = __('Sales channels: :list', [
                'list' => $this->shorten(collect($report['channels'])
                    ->map(fn (int $count, string $channel): string => Order::salesChannelLabel($channel).' '.$count)
                    ->values()
                    ->all()),
            ]);
        }

        if ($report['bookings'] !== []) {
            $replace = [
                'count' => array_sum($report['bookings']),
                'list' => $this->shorten(collect($report['bookings'])
                    ->map(fn (int $count, string $partner): string => $partner.' '.$count)
                    ->values()
                    ->all()),
            ];

            $lines[] = $dryRun
                ? __(':count courier bookings would be recorded: :list', $replace)
                : __(':count courier bookings were recorded: :list', $replace);
        }

        if ($report['new_providers'] !== []) {
            $replace = [
                'count' => count($report['new_providers']),
                'list' => $this->shorten(array_keys($report['new_providers'])),
            ];

            $lines[] = $dryRun
                ? __(':count couriers would be created (inactive, no API): :list', $replace)
                : __(':count couriers were created (inactive, no API): :list', $replace);
        }

        if ($report['open_consignments'] > 0) {
            $lines[] = __(':count orders have a parcel still on the way; its consignment number stays in the order note only.', [
                'count' => $report['open_consignments'],
            ]);
        }

        if ($unmatched > 0) {
            $lines[] = __(':count orders were skipped because a product was not found.', ['count' => $unmatched]);
            $lines[] = __('Products not found (SKU or name): :list', ['list' => $this->shorten(array_keys($report['unmatched']))]);
            $lines[] = __('Add these SKUs to the SKU mapping, or turn on "Create missing products", then import the same file again.');
        }

        if ($other > 0) {
            $lines[] = __(':count orders were skipped for another reason: :list', [
                'count' => $other,
                'list' => $this->shorten(collect($report['skipped_orders'])
                    ->reject(fn (array $skipped): bool => in_array($skipped['reason'], ['test_or_fake', 'unmatched_sku'], true))
                    ->map(fn (array $skipped): string => $skipped['invoice'].' ('.$skipped['reason'].')')
                    ->values()
                    ->all()),
            ]);
        }

        $lines[] = __('New customers: :new. Customers found: :existing.', [
            'new' => $report['customers_new'],
            'existing' => $report['customers_existing'],
        ]);

        if ($report['customers_with_balance'] > 0) {
            $lines[] = __(':count customers already have an opening balance. Check it so their dues are not counted twice.', [
                'count' => $report['customers_with_balance'],
            ]);
        }

        if ($report['mismatch'] !== []) {
            $lines[] = __('Totals do not match the lines on :count orders (the file totals are used): :list', [
                'count' => count($report['mismatch']),
                'list' => $this->shorten($report['mismatch']),
            ]);
        }

        if ($report['errors'] !== []) {
            $lines[] = __('Errors: :list', ['list' => $this->shorten($report['errors'])]);
        }

        $lines[] = __('Stock and account balances are never changed by this import.');

        if ($dryRun) {
            $lines[] = __('Preview only: nothing was saved.');
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    protected function emptyReport(int $orders): array
    {
        return [
            'dry_run' => true,
            'orders_in_file' => $orders,
            'created' => 0,
            'refreshed' => 0,
            'unchanged' => 0,
            'skipped' => [],
            'skipped_orders' => [],
            'unmatched' => [],
            'mapped' => [],
            'map_ignored' => [],
            'new_products' => [],
            'channels' => [],
            'bookings' => [],
            'new_providers' => [],
            'open_consignments' => 0,
            'mismatch' => [],
            'errors' => [],
            'ignored_lines' => 0,
            'customers_new' => 0,
            'customers_existing' => 0,
            'customers_with_balance' => 0,
            'by_status' => [],
        ];
    }

    /**
     * @return array<string, array<int, array<string, string>>> rows grouped by invoice, in file order
     */
    protected function readInvoices(string $path): array
    {
        $handle = @fopen($path, 'r');

        if (! $handle) {
            throw ValidationException::withMessages(['csv' => __('Unable to read the uploaded CSV file.')]);
        }

        try {
            $headings = array_map(fn ($heading): string => $this->headingKey((string) $heading), fgetcsv($handle) ?: []);
            $missing = array_diff(self::REQUIRED_COLUMNS, $headings);

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'csv' => __('The CSV is missing these columns: :columns', ['columns' => implode(', ', $missing)]),
                ]);
            }

            $invoices = [];

            while (($row = fgetcsv($handle)) !== false) {
                $data = [];

                foreach ($headings as $index => $key) {
                    if ($key !== '') {
                        $data[$key] = trim((string) ($row[$index] ?? ''));
                    }
                }

                if (($data['invoice'] ?? '') !== '') {
                    $invoices[$data['invoice']][] = $data;
                }
            }
        } finally {
            fclose($handle);
        }

        return $invoices;
    }

    /** "Payments/Paid Amount" and "payments paid amount" both become "paymentspaidamount". */
    protected function headingKey(string $heading): string
    {
        return Str::of($heading)
            ->replace("\xEF\xBB\xBF", '')
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();
    }

    protected function loadProducts(): void
    {
        $this->variants = ProductVariant::query()->with('product')->get()
            ->filter(fn (ProductVariant $variant): bool => filled($variant->sku))
            ->keyBy(fn (ProductVariant $variant): string => Str::lower(trim((string) $variant->sku)));

        $products = Product::query()->get();

        $this->productsBySku = $products
            ->filter(fn (Product $product): bool => filled($product->sku))
            ->keyBy(fn (Product $product): string => Str::lower(trim((string) $product->sku)));

        $this->productsByName = $products
            ->keyBy(fn (Product $product): string => Str::lower(trim((string) $product->name)));
    }

    /**
     * @param  array<int, string>  $invoices
     * @return Collection<string, Order>
     */
    protected function existingOrders(array $invoices): Collection
    {
        $orders = collect();

        foreach (array_chunk($invoices, 500) as $chunk) {
            $orders = $orders->concat(
                Order::query()->withTrashed()->whereIn('external_reference', $chunk)->get(),
            );
        }

        return $orders->keyBy('external_reference');
    }

    /**
     * Everything needed to create or refresh one order, or why it is skipped.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<string, mixed>
     */
    protected function plan(string $invoice, array $rows): array
    {
        $head = $rows[0];
        $plan = [
            'invoice' => $invoice,
            'skip' => null,
            'detail' => null,
            'head' => $head,
            'lines' => [],
            'mapped' => [],
            'ignored_lines' => 0,
            'mismatch' => false,
        ];

        $nuportStatus = Str::upper(str_replace([' ', '-'], '_', $head['status'] ?? ''));
        $reason = Str::lower(trim($head['cancelledflaggedreason'] ?? ''));

        if ($nuportStatus === 'CANCELLED' && in_array($reason, self::SKIPPED_CANCEL_REASONS, true)) {
            return array_merge($plan, ['skip' => 'test_or_fake']);
        }

        if (! isset(self::STATUS_MAP[$nuportStatus])) {
            return array_merge($plan, ['skip' => 'unsupported_status', 'detail' => $head['status'] ?? '']);
        }

        $createdAt = $this->parseDate($head['creationdate'] ?? '');

        if (! $createdAt) {
            return array_merge($plan, ['skip' => 'bad_date', 'detail' => $head['creationdate'] ?? '']);
        }

        $linesTotal = 0.0;
        $afterDiscountTotal = 0.0;

        foreach ($rows as $row) {
            $quantity = (int) round($this->money($row['productqty'] ?? ''));

            if ($quantity <= 0) {
                $plan['ignored_lines']++;

                continue;
            }

            $unitPrice = $this->money($row['unitprice'] ?? '');
            $resolved = $this->resolveProduct($row['productsku'] ?? '', $row['productname'] ?? '');

            if (! $resolved && $this->createMissing) {
                $resolved = $this->planNewProduct($row['productsku'] ?? '', $row['productname'] ?? '', $unitPrice);
            }

            if (! $resolved) {
                $plan['unmatched'][$this->productLabel($row)] = true;

                continue;
            }

            if (isset($resolved['mapped_from'])) {
                $plan['mapped'][$resolved['mapped_from']] = true;
            }

            // A cancelled order never shipped, so its "cancelled" pieces are not returns.
            $returned = $nuportStatus === 'CANCELLED'
                ? 0
                : max(0, min($quantity, (int) round($this->money($row['cancelledreturneddamagedqty'] ?? ''))));

            $plan['lines'][] = $resolved + ['quantity' => $quantity, 'unit_price' => $unitPrice, 'returned_quantity' => $returned];
            $linesTotal += $quantity * $unitPrice;
            $afterDiscountTotal += $this->money($row['priceafterdiscount'] ?? $row['totalprice'] ?? '');
        }

        if (isset($plan['unmatched'])) {
            return array_merge($plan, ['skip' => 'unmatched_sku', 'detail' => implode(', ', array_keys($plan['unmatched']))]);
        }

        if ($plan['lines'] === []) {
            return array_merge($plan, ['skip' => 'no_lines']);
        }

        $sales = $this->money($head['salesamount'] ?? '');
        $fee = $this->money($head['deliveryfee'] ?? '');
        $paid = $this->money($head['paymentspaidamount'] ?? '');
        $shippedAt = $this->parseDate($head['shippingdate'] ?? '');
        $deliveredAt = $this->parseDate($head['deliveredat'] ?? '');
        $lastDate = $deliveredAt ?? $shippedAt ?? $createdAt;

        // Sales Amount excludes the delivery fee. Lines at list price minus
        // Sales Amount is the discount; the (never seen) opposite case goes
        // to the packaging fee so the ERP total still equals Nuport's.
        $plan['mismatch'] = abs($afterDiscountTotal - $this->money($head['orderdiscount'] ?? '') - $sales) > 0.01;

        $deliveryStatus = $this->deliveryStatus($nuportStatus, $head);
        $booking = $this->bookingPlan($head, $deliveryStatus, $sales + $fee, $shippedAt ?? $createdAt, $deliveredAt, $lastDate);

        return array_merge($plan, [
            'status' => self::STATUS_MAP[$nuportStatus],
            'delivery_status' => $deliveryStatus,
            'sales_channel' => $this->salesChannel($head['ordersource'] ?? ''),
            'booking' => $booking,
            'open_consignment' => $booking === null && ($head['deliveryid'] ?? '') !== '',
            'discount' => round(max($linesTotal - $sales, 0), 2),
            'packaging_fee' => round(max($sales - $linesTotal, 0), 2),
            'shipping_fee' => round($fee, 2),
            'sales' => round($sales, 2),
            'paid' => round($paid, 2),
            'due' => round(max($sales + $fee - $paid, 0), 2),
            'created_at' => $createdAt,
            'updated_at' => $lastDate,
            'paid_at' => $lastDate->toDateString(),
            'payment_label' => trim($head['paymentmethod'] ?? ''),
            'payment_method' => $this->paymentMethod($head['paymentmethod'] ?? ''),
            'note' => $this->note($invoice, $head),
        ]);
    }

    /** @param  array<string, string>  $head */
    protected function deliveryStatus(string $nuportStatus, array $head): string
    {
        $hasBooking = ($head['deliveryid'] ?? '') !== '';

        return match ($nuportStatus) {
            'DELIVERED' => Str::lower($head['deliverypartnerstatus'] ?? '') === 'partial_delivered'
                ? CourierBooking::STATUS_PARTIAL_DELIVERED
                : CourierBooking::STATUS_DELIVERED,
            'IN_TRANSIT' => CourierBooking::STATUS_IN_TRANSIT,
            'FLAGGED' => CourierBooking::STATUS_RETURNED,
            'CANCELLED' => $hasBooking ? CourierBooking::STATUS_CANCELLED : CourierBooking::STATUS_NOT_BOOKED,
            default => $hasBooking ? CourierBooking::STATUS_BOOKED : CourierBooking::STATUS_NOT_BOOKED,
        };
    }

    /** "LIVE_CHAT" and "Live chat" are the same channel: lower-case words joined by underscores. */
    protected function salesChannel(string $source): ?string
    {
        $channel = trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower($source)), '_');

        return $channel === '' ? null : Str::limit($channel, 40, '');
    }

    /**
     * The courier booking to record for a parcel whose delivery is over, or
     * null (no consignment number, or the parcel is still on the way).
     *
     * @param  array<string, string>  $head
     * @return array<string, mixed>|null
     */
    protected function bookingPlan(array $head, string $deliveryStatus, float $total, Carbon $bookedAt, ?Carbon $deliveredAt, Carbon $lastDate): ?array
    {
        $tracking = trim($head['deliveryid'] ?? '');

        if ($tracking === '' || ! in_array($deliveryStatus, self::BOOKING_STATUSES, true)) {
            return null;
        }

        $delivered = in_array($deliveryStatus, [CourierBooking::STATUS_DELIVERED, CourierBooking::STATUS_PARTIAL_DELIVERED], true);
        $collectsCash = (bool) preg_match('/cash on delivery|\bcod\b/', Str::lower($head['paymentmethod'] ?? ''));
        // Nuport marks a parcel that came back as RETURNED; PENDING_RETURNED is still on its way back.
        $backAtShop = $deliveryStatus === CourierBooking::STATUS_RETURNED && Str::upper(trim($head['substatus'] ?? '')) === 'RETURNED';

        return [
            'partner' => trim($head['deliverypartner'] ?? '') ?: 'Courier',
            'tracking_id' => $tracking,
            'status' => $deliveryStatus,
            'cod_amount' => $collectsCash ? round($total, 2) : 0.0,
            'delivery_fee_charged' => round($this->money($head['deliveryfee'] ?? ''), 2),
            'booked_at' => $bookedAt,
            'delivered_at' => $delivered ? ($deliveredAt ?? $lastDate) : null,
            'returned_at' => $backAtShop ? $lastDate : null,
        ];
    }

    protected function findProvider(string $partner): ?CourierProvider
    {
        $this->providers ??= CourierProvider::query()->orderByDesc('is_active')->orderBy('id')->get();
        $slug = Str::slug($partner);

        return $this->providers->first(fn (CourierProvider $provider): bool => in_array($provider->driver, CourierProvider::API_DRIVERS, true) && $provider->driver === $slug)
            ?? $this->providers->first(fn (CourierProvider $provider): bool => $provider->slug === $slug || Str::lower(trim($provider->name)) === Str::lower(trim($partner)));
    }

    /** The company's courier of that name, or a new inactive one with no API. */
    protected function providerFor(string $partner): CourierProvider
    {
        $provider = $this->findProvider($partner);

        if ($provider) {
            return $provider;
        }

        $provider = CourierProvider::query()->create([
            'company_id' => $this->company->getKey(),
            'name' => $partner,
            'slug' => Str::slug($partner) ?: 'courier',
            'driver' => CourierProvider::DRIVER_MANUAL,
            'is_active' => false,
        ]);

        $this->providers->push($provider);

        return $provider;
    }

    /**
     * Keeps the one booking record of an order's consignment in line with the
     * file. Quiet on purpose: no status logs, SMS or customer risk review for
     * history. Returns whether anything changed.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function syncBooking(Order $order, array $plan, ?Customer $customer = null): bool
    {
        $booking = $plan['booking'] ?? null;

        if ($booking === null) {
            return false;
        }

        $customer ??= $order->customer;
        $row = CourierBooking::query()
            ->where('order_id', $order->getKey())
            ->where('tracking_id', $booking['tracking_id'])
            ->first() ?? new CourierBooking;

        $row->forceFill([
            'company_id' => $order->company_id,
            'courier_provider_id' => $this->providerFor($booking['partner'])->getKey(),
            'order_id' => $order->getKey(),
            'tracking_id' => $booking['tracking_id'],
            'recipient_name' => $customer?->name ?: ($order->customer_name ?: 'Customer'),
            'recipient_phone' => $customer?->phone,
            'recipient_address' => ($plan['head']['customeraddress'] ?? '') !== '' ? $plan['head']['customeraddress'] : $customer?->address,
            'cod_amount' => $booking['cod_amount'],
            'delivery_fee_charged' => $booking['delivery_fee_charged'],
            'status' => $booking['status'],
            'booked_at' => $this->databaseTime($booking['booked_at']),
            'delivered_at' => $this->databaseTime($booking['delivered_at']),
            'returned_at' => $this->databaseTime($booking['returned_at']),
            'note' => self::PAYMENT_NOTE,
        ]);

        if (! $row->exists) {
            $row->forceFill([
                'created_at' => $this->databaseTime($booking['booked_at']),
                'updated_at' => $this->databaseTime($booking['booked_at']),
            ]);
        }

        $changed = ! $row->exists || $row->isDirty();
        $row->saveQuietly();

        return $changed;
    }

    /** Company-timezone time as the application timezone the database stores. */
    protected function databaseTime(?Carbon $time): ?Carbon
    {
        return $time?->copy()->setTimezone(config('app.timezone'));
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $plan
     */
    protected function addChannelAndBookingTotals(array &$report, array $plan): void
    {
        if ($plan['sales_channel'] !== null) {
            $report['channels'][$plan['sales_channel']] = ($report['channels'][$plan['sales_channel']] ?? 0) + 1;
        }

        if ($plan['open_consignment']) {
            $report['open_consignments']++;
        }

        if ($plan['booking'] === null) {
            return;
        }

        $partner = $plan['booking']['partner'];
        $report['bookings'][$partner] = ($report['bookings'][$partner] ?? 0) + 1;

        if ($this->findProvider($partner) === null) {
            $report['new_providers'][$partner] = true;
        }
    }

    protected function paymentMethod(string $label): string
    {
        $label = Str::lower($label);

        return match (true) {
            str_contains($label, 'bkash'), str_contains($label, 'nagad'), str_contains($label, 'rocket') => 'mobile_banking',
            str_contains($label, 'bank') => 'bank',
            str_contains($label, 'card') => 'card',
            str_contains($label, 'cash') => 'cash',
            default => 'other',
        };
    }

    /** @param  array<string, string>  $head */
    protected function note(string $invoice, array $head): string
    {
        $parts = [];

        if (($head['additionalnote'] ?? '') !== '') {
            $parts[] = $head['additionalnote'];
        }

        $internal = trim((string) preg_replace('/\s*\n+\s*/', ' ', $head['internalnotes'] ?? ''));

        if ($internal !== '') {
            $parts[] = 'Internal: '.$internal;
        }

        if (($head['cancelledflaggedreason'] ?? '') !== '') {
            $parts[] = 'Reason: '.$head['cancelledflaggedreason'];
        }

        if (($head['deliveryid'] ?? '') !== '') {
            $parts[] = trim(($head['deliverypartner'] ?? '') ?: 'Courier').' consignment: '.$head['deliveryid'];
        }

        $parts[] = 'Imported from Nuport '.$invoice;

        return implode("\n", $parts);
    }

    /** "August 21st 2025, 6:19:19 pm" in the company's timezone. */
    protected function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $clean = (string) preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $value);

        try {
            return Carbon::createFromFormat('F j Y, g:i:s a', $clean, $this->timezone) ?: null;
        } catch (Throwable) {
            try {
                return Carbon::parse($clean, $this->timezone);
            } catch (Throwable) {
                return null;
            }
        }
    }

    protected function money(string $value): float
    {
        $value = str_replace(',', '', trim($value));

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** @param  array<string, string>  $row */
    protected function productLabel(array $row): string
    {
        return ($row['productsku'] ?? '') !== '' ? $row['productsku'] : ($row['productname'] ?? '').' (no SKU)';
    }

    /**
     * The SKU mapping first (it names an ERP SKU), then variant SKU, then
     * product SKU, then the exact product name for a line that has no SKU.
     *
     * @return array{product_id: ?int, variant_id: ?int, label: ?string, unit_cost: float, mapped_from: ?string}|null
     */
    protected function resolveProduct(string $sku, string $name): ?array
    {
        $sku = Str::lower(trim($sku));
        $mappedFrom = null;

        if ($sku !== '' && isset($this->skuMap[$sku])) {
            $mappedFrom = $sku;
            $sku = $this->skuMap[$sku]['to'];
        }

        if ($sku !== '') {
            if ($variant = $this->variants->get($sku)) {
                return [
                    'product_id' => (int) $variant->product_id,
                    'variant_id' => $variant->getKey(),
                    'label' => $variant->label() ?: null,
                    'unit_cost' => $variant->effectiveCostPrice(),
                    'mapped_from' => $mappedFrom,
                ];
            }

            $product = $this->productsBySku->get($sku);
        } else {
            $product = $this->productsByName->get(Str::lower(trim($name)));
        }

        return $product
            ? ['product_id' => $product->getKey(), 'variant_id' => null, 'label' => null, 'unit_cost' => (float) $product->cost_price, 'mapped_from' => $mappedFrom]
            : null;
    }

    /**
     * Lines of "NUPORT-SKU = ERP-SKU" (also "=>", "→", a comma or a tab; blank
     * lines and lines starting with # are comments). A line whose ERP SKU is
     * neither a variant nor a product SKU, or that has no two parts, is
     * ignored and reported.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, array{from: string, to: string, label: string}>
     */
    protected function parseSkuMap(string $text, array &$report): array
    {
        $map = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $understood = preg_match('/^(.+?)\s*(?:=>|→|=|,|;|\t)\s*(.+)$/u', $line, $parts) === 1;
            $from = $understood ? trim($parts[1]) : '';
            $to = $understood ? trim($parts[2]) : '';
            $target = Str::lower($to);

            if ($from === '' || $to === '' || ! ($this->variants->has($target) || $this->productsBySku->has($target))) {
                $report['map_ignored'][] = $line;

                continue;
            }

            $map[Str::lower($from)] = ['from' => $from, 'to' => $target, 'label' => $to];
        }

        return $map;
    }

    /**
     * A line no SKU, mapping or name finds, when "Create missing products" is
     * on: remembers the product to create and gives the line a placeholder
     * until createNewProduct() saves it.
     *
     * @return array{product_id: null, variant_id: null, label: null, unit_cost: float, mapped_from: null, new: string}|null
     */
    protected function planNewProduct(string $sku, string $name, float $unitPrice): ?array
    {
        $sku = trim($sku);
        $name = trim($name);

        if ($sku === '') {
            if ($name === '') {
                return null;
            }

            $slug = Str::upper(Str::slug($name));
            $sku = 'NUPORT-'.($slug !== '' ? $slug : strtoupper(substr(md5($name), 0, 8)));
        }

        $key = Str::lower($sku);
        $entry = $this->newProducts[$key] ?? ['sku' => $sku, 'name' => $name !== '' ? $name : $sku, 'price' => 0.0, 'product_id' => null];

        // The last non-zero price in the file is the most recent one.
        if ($unitPrice > 0 && ! $this->pricesLocked) {
            $entry['price'] = $unitPrice;
        }

        $this->newProducts[$key] = $entry;

        return ['product_id' => null, 'variant_id' => null, 'label' => null, 'unit_cost' => 0.0, 'mapped_from' => null, 'new' => $key];
    }

    /**
     * Saves the products the lines need and returns the lines with their ids.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function materialiseLines(array $lines): array
    {
        foreach ($lines as $index => $line) {
            if (isset($line['new'])) {
                $lines[$index]['product_id'] = $this->createNewProduct((string) $line['new']);
            }
        }

        return $lines;
    }

    /** Inactive, stock 0, cost price 0: history only, never on the website or in stock. */
    protected function createNewProduct(string $key): int
    {
        $entry = $this->newProducts[$key];

        if ($entry['product_id'] !== null) {
            return $entry['product_id'];
        }

        $product = $this->productsBySku->get($key) ?? Product::query()->create([
            'company_id' => $this->company->getKey(),
            'name' => $entry['name'],
            'sku' => $entry['sku'],
            'price' => $entry['price'],
            'sale_price' => $entry['price'],
            'cost_price' => 0,
            'stock' => 0,
            'unit' => 'pcs',
            'is_active' => false,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        $this->productsBySku->put($key, $product);
        $this->productsByName->put(Str::lower(trim((string) $product->name)), $product);
        $this->newProducts[$key]['product_id'] = (int) $product->getKey();

        return $this->newProducts[$key]['product_id'];
    }

    /**
     * 01712345678, +8801712345678 and 8801712345678 are one customer. A phone
     * that is not a Bangladeshi mobile number is matched on the exact text
     * plus the name, so two unrelated junk numbers never merge.
     *
     * @param  array<string, string>  $head
     * @param  array<string, mixed>  $report
     */
    protected function resolveCustomer(array $head, array &$report, bool $createMissing): ?Customer
    {
        $name = trim($head['customername'] ?? '');
        $rawPhone = trim($head['customerphonenumber'] ?? '');
        $local = app(StorefrontCheckoutPolicyService::class)->normalizeLocalPhone($rawPhone);
        $key = $local ?? 'raw:'.Str::lower($rawPhone).'|'.Str::lower($name);

        if (! array_key_exists($key, $this->customerCache)) {
            $customer = match (true) {
                $local !== null => Customer::query()->whereIn('phone', [$local, '+88'.$local, '88'.$local])->orderBy('id')->first(),
                $rawPhone !== '' => Customer::query()->where('phone', $rawPhone)->where('name', $name)->orderBy('id')->first(),
                $name !== '' => Customer::query()->whereNull('phone')->where('name', $name)->orderBy('id')->first(),
                default => null,
            };

            $isNew = $customer === null;

            if ($isNew) {
                $report['customers_new']++;
            } else {
                $report['customers_existing']++;

                if ((float) $customer->opening_balance !== 0.0) {
                    $report['customers_with_balance']++;
                }
            }

            $this->customerCache[$key] = ['customer' => $customer, 'new' => $isNew];
        }

        if ($this->customerCache[$key]['customer'] === null && $createMissing) {
            $this->customerCache[$key]['customer'] = Customer::query()->create([
                'company_id' => $this->company->getKey(),
                'name' => $name !== '' ? $name : ($rawPhone !== '' ? $rawPhone : 'Unknown customer'),
                'phone' => $local ?? ($rawPhone !== '' ? $rawPhone : null),
                'address' => ($head['customeraddress'] ?? '') !== '' ? $head['customeraddress'] : null,
                'customer_type' => 'regular',
                'opening_balance' => 0,
                'is_active' => true,
            ]);
        }

        return $this->customerCache[$key]['customer'];
    }

    /** @param  array<string, mixed>  $plan */
    protected function createOrder(array $plan, Customer $customer): void
    {
        $plan['lines'] = $this->materialiseLines($plan['lines']);

        DB::transaction(function () use ($plan, $customer): void {
            // The courier of a recorded parcel; any other order keeps the company's default courier.
            $provider = $plan['booking'] !== null ? $this->providerFor($plan['booking']['partner']) : null;

            $order = new Order;
            $order->forceFill([
                'company_id' => $this->company->getKey(),
                'order_number' => $plan['invoice'],
                'external_reference' => $plan['invoice'],
                'customer_id' => $customer->getKey(),
                'customer_name' => $customer->name,
                'order_date' => $plan['created_at']->toDateString(),
                'discount' => $plan['discount'],
                'vat' => 0,
                'shipping_zone' => self::SHIPPING_ZONE,
                'shipping_fee' => $plan['shipping_fee'],
                'packaging_fee' => $plan['packaging_fee'],
                'paid_amount' => 0,
                'status' => $plan['status'],
                'delivery_status' => $plan['delivery_status'],
                'courier_provider_id' => $provider?->getKey(),
                'source' => Order::SOURCE_NUPORT_IMPORT,
                'sales_channel' => $plan['sales_channel'],
                'note' => $plan['note'],
                'created_at' => $plan['created_at']->copy()->setTimezone(config('app.timezone')),
                'updated_at' => $plan['updated_at']->copy()->setTimezone(config('app.timezone')),
            ])->save();

            // One workflow sync after all lines instead of one per line.
            OrderItem::withoutEvents(function () use ($order, $plan): void {
                foreach ($plan['lines'] as $line) {
                    OrderItem::query()->create([
                        'company_id' => $order->company_id,
                        'order_id' => $order->getKey(),
                        'product_id' => $line['product_id'],
                        'product_variant_id' => $line['variant_id'],
                        'variant_label' => $line['label'],
                        'quantity' => $line['quantity'],
                        'returned_quantity' => $line['returned_quantity'],
                        'unit_price' => $line['unit_price'],
                        'unit_cost' => $line['unit_cost'],
                        'subtotal' => $line['quantity'] * $line['unit_price'],
                        'is_upsell' => false,
                    ]);
                }
            });

            $order->syncTotalsStockAndCustomerBalance();
            $this->syncPayment($order->refresh(), $plan);
            $this->syncBooking($order, $plan, $customer);
            $order->syncCustomerBalance();
        });
    }

    /**
     * Refreshes an order imported earlier from a newer export. Items, notes
     * and the customer stay as they are.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function refreshOrder(Order $order, array $plan): bool
    {
        return DB::transaction(function () use ($order, $plan): bool {
            $order->fill($this->refreshAttributes($plan));
            $changed = $order->isDirty();
            $order->saveQuietly();

            $order->syncTotalsStockAndCustomerBalance();
            $paymentChanged = $this->syncPayment($order->refresh(), $plan);
            $bookingChanged = $this->syncBooking($order, $plan);
            $order->syncCustomerBalance();

            return $changed || $paymentChanged || $bookingChanged;
        });
    }

    /** @param  array<string, mixed>  $plan */
    protected function wouldChange(Order $order, array $plan): bool
    {
        $order->fill($this->refreshAttributes($plan));

        $payment = $this->desiredPayment($plan);
        $current = (float) $order->payments()->where('note', 'like', self::PAYMENT_NOTE.'%')->sum('amount');

        $bookingMissing = $plan['booking'] !== null && ! $order->courierBookings()
            ->where('tracking_id', $plan['booking']['tracking_id'])
            ->where('status', $plan['booking']['status'])
            ->exists();

        return $order->isDirty() || abs($current - $payment) > 0.005 || $bookingMissing;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function refreshAttributes(array $plan): array
    {
        return [
            'status' => $plan['status'],
            'delivery_status' => $plan['delivery_status'],
            'sales_channel' => $plan['sales_channel'],
            'discount' => $plan['discount'],
            'shipping_fee' => $plan['shipping_fee'],
            'packaging_fee' => $plan['packaging_fee'],
        ];
    }

    /** @param  array<string, mixed>  $plan */
    protected function desiredPayment(array $plan): float
    {
        return $plan['status'] === Order::STATUS_CANCELLED ? 0.0 : (float) $plan['paid'];
    }

    /**
     * Keeps exactly one account-less payment row that mirrors Nuport's paid
     * amount. Returns whether anything changed.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function syncPayment(Order $order, array $plan): bool
    {
        $desired = $this->desiredPayment($plan);
        $existing = $order->payments()->where('note', 'like', self::PAYMENT_NOTE.'%')->get();

        if ($existing->count() === 1 && abs((float) $existing->first()->amount - $desired) < 0.005) {
            return false;
        }

        if ($existing->isEmpty() && $desired <= 0) {
            return false;
        }

        $existing->each->delete();

        if ($desired <= 0) {
            return true;
        }

        $order->payments()->create([
            'company_id' => $order->company_id,
            'type' => $desired >= (float) $order->total_amount ? OrderPayment::TYPE_FINAL : OrderPayment::TYPE_PARTIAL,
            'method' => $plan['payment_method'],
            'account_id' => null,
            'amount' => $desired,
            'note' => $plan['payment_label'] !== '' ? self::PAYMENT_NOTE." ({$plan['payment_label']})" : self::PAYMENT_NOTE,
            'paid_at' => $plan['paid_at'],
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $plan
     */
    protected function recordSkip(array &$report, array $plan): void
    {
        $reason = (string) $plan['skip'];
        $report['skipped'][$reason] = ($report['skipped'][$reason] ?? 0) + 1;
        $report['skipped_orders'][] = ['invoice' => $plan['invoice'], 'reason' => $reason, 'detail' => $plan['detail'] ?? null];

        foreach (array_keys($plan['unmatched'] ?? []) as $label) {
            $report['unmatched'][$label] = ($report['unmatched'][$label] ?? 0) + 1;
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $plan
     */
    protected function addStatusTotals(array &$report, array $plan): void
    {
        $totals = $report['by_status'][$plan['status']] ?? ['orders' => 0, 'sales' => 0.0, 'paid' => 0.0, 'due' => 0.0];
        $totals['orders']++;
        $totals['sales'] += $plan['sales'];
        $totals['paid'] += $this->desiredPayment($plan);
        $totals['due'] += $plan['status'] === Order::STATUS_CANCELLED ? 0.0 : $plan['due'];

        $report['by_status'][$plan['status']] = $totals;
    }

    /** @param  array<int, string>  $items */
    protected function shorten(array $items): string
    {
        $shown = array_slice($items, 0, self::LIST_LIMIT);

        return implode(', ', $shown).(count($items) > self::LIST_LIMIT ? ', … (+'.(count($items) - self::LIST_LIMIT).')' : '');
    }
}
