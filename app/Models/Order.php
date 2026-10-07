<?php

namespace App\Models;

use App\Jobs\PushWooCommerceOrderStatusJob;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GeneratesSequentialNumber;
use App\Services\CustomerRiskService;
use App\Services\OrderWorkflowService;
use App\Services\ResellerCommissionService;
use App\Services\ShippingFeeService;
use App\Services\WebsiteWebhookDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class Order extends Model
{
    use BelongsToCompany, GeneratesSequentialNumber, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_CONFIRMED => 'Confirmed',
        self::STATUS_PROCESSING => 'Processing',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
        self::STATUS_RETURNED => 'Returned',
        self::STATUS_REFUNDED => 'Refunded',
    ];

    /** Statuses that reserve stock and count towards sales/customer dues. */
    public const ACCOUNTED_STATUSES = [
        self::STATUS_CONFIRMED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
    ];

    public const STAGE_CONFIRMED = 'confirmed';

    public const STAGE_PROCESSING = 'processing';

    public const STAGE_SHIPPING = 'shipping';

    public const STAGE_DELIVERED = 'delivered';

    public const STAGE_COMPLETED = 'completed';

    public const STAGE_CANCELLED = 'cancelled';

    public const STAGE_RETURNED = 'returned';

    public const STAGE_REFUNDED = 'refunded';

    public const WORKFLOW_STAGES = [
        self::STAGE_CONFIRMED => 'Confirmed',
        self::STAGE_PROCESSING => 'Processing',
        self::STAGE_SHIPPING => 'Shipping',
        self::STAGE_DELIVERED => 'Delivered',
        self::STAGE_COMPLETED => 'Completed',
        self::STAGE_CANCELLED => 'Cancelled',
        self::STAGE_RETURNED => 'Returned',
        self::STAGE_REFUNDED => 'Refunded',
    ];

    public const REASON_REQUIRED_STAGES = [
        self::STAGE_CANCELLED,
        self::STAGE_RETURNED,
        self::STAGE_REFUNDED,
    ];

    public const WORKFLOW_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STAGE_CONFIRMED, self::STAGE_CANCELLED],
        self::STATUS_CONFIRMED => [self::STAGE_PROCESSING, self::STAGE_SHIPPING, self::STAGE_DELIVERED, self::STAGE_COMPLETED, self::STAGE_CANCELLED, self::STAGE_RETURNED],
        self::STATUS_PROCESSING => [self::STAGE_SHIPPING, self::STAGE_DELIVERED, self::STAGE_COMPLETED, self::STAGE_CANCELLED, self::STAGE_RETURNED],
        self::STAGE_SHIPPING => [self::STAGE_DELIVERED, self::STAGE_RETURNED, self::STAGE_CANCELLED],
        self::STATUS_COMPLETED => [self::STAGE_RETURNED, self::STAGE_REFUNDED],
        self::STATUS_RETURNED => [self::STAGE_REFUNDED],
        self::STATUS_CANCELLED => [],
        self::STATUS_REFUNDED => [],
    ];

    public ?string $statusTransitionStage = null;

    public ?string $statusTransitionReason = null;

    public string $statusTransitionSource = 'direct';

    public ?int $statusTransitionActorId = null;

    /**
     * Set by WooCommerceOrderSyncService before saving an order it just
     * updated FROM an incoming WooCommerce webhook, so the updated() hook
     * below doesn't push that same status straight back to WooCommerce —
     * without this, every WooCommerce → ERP sync would immediately trigger
     * an ERP → WooCommerce push of the identical status, a wasteful (though
     * not infinite, since the status wouldn't keep changing) round trip.
     */
    public bool $suppressWooCommercePush = false;

    /**
     * The account (Cash, bKash, bank…) the "Paid Amount" typed on the
     * create form was received into — set by CreateOrder and used by the
     * `created` hook for the first Payments History row. Not a column.
     */
    public ?int $initialPaymentAccountId = null;

    public const DELIVERY_STATUSES = [
        CourierBooking::STATUS_NOT_BOOKED => 'Not Booked',
        CourierBooking::STATUS_BOOKING_PENDING => 'Booking Pending',
        CourierBooking::STATUS_BOOKED => 'Booked',
        CourierBooking::STATUS_PICKED_UP => 'Picked Up',
        CourierBooking::STATUS_IN_TRANSIT => 'In Transit',
        CourierBooking::STATUS_DELIVERED => 'Delivered',
        CourierBooking::STATUS_PARTIAL_DELIVERED => 'Partial Delivered',
        CourierBooking::STATUS_RETURNED => 'Returned',
        CourierBooking::STATUS_CANCELLED => 'Cancelled',
        CourierBooking::STATUS_FAILED => 'Failed',
    ];

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_STOREFRONT = 'storefront';

    public const SOURCE_CRM = 'crm';

    public const SOURCE_CHAT = 'chat';

    public const SOURCE_OFFER = 'offer';

    public const SOURCE_WOOCOMMERCE = 'woocommerce';

    public const SOURCE_API = 'website_api';

    public const SOURCE_RESELLER_PORTAL = 'reseller_portal';

    public const SOURCES = [
        self::SOURCE_ADMIN => 'Admin',
        self::SOURCE_STOREFRONT => 'Storefront',
        self::SOURCE_CRM => 'CRM',
        self::SOURCE_CHAT => 'Chat',
        self::SOURCE_OFFER => 'Offer',
        self::SOURCE_WOOCOMMERCE => 'WooCommerce',
        self::SOURCE_API => 'Website (API)',
        self::SOURCE_RESELLER_PORTAL => 'Reseller portal',
    ];

    protected $fillable = [
        'company_id',
        'storefront_setting_id',
        'order_number',
        'customer_id',
        'reseller_customer_id',
        'reseller_own_courier',
        'reseller_courier_name',
        'reseller_parcel_id',
        'reseller_parcel_packed_at',
        'customer_name',
        'order_date',
        'subtotal',
        'discount',
        'vat',
        'shipping_zone',
        'shipping_fee',
        'packaging_fee',
        'total_amount',
        'paid_amount',
        'due_amount',
        'status',
        'delivery_status',
        'courier_provider_id',
        'source',
        'external_reference',
        'note',
    ];

    protected $casts = [
        'order_date' => 'date',
        'reseller_own_courier' => 'boolean',
        'reseller_parcel_packed_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'vat' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'packaging_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->order_number ??= static::nextOrderNumber($order->company);
            $order->order_date ??= now()->toDateString();
            $order->status ??= self::STATUS_DRAFT;
            $order->delivery_status ??= CourierBooking::STATUS_NOT_BOOKED;
            $order->source ??= self::SOURCE_ADMIN;
            $order->customer_name = $order->customer?->name ?? $order->customer_name;

            // Pre-assigns the company's default courier (Courier Providers →
            // "Set as default") to every new order regardless of where it
            // was created — storefront checkout and admin-created orders
            // both go through Order::create(), so this one hook covers
            // both. Purely a preference for whoever books the courier
            // later; it doesn't book anything by itself, and can still be
            // changed on the order's Delivery Status section before booking.
            // A reseller's own-courier order is never booked with the
            // company's courier, so it gets no default provider.
            if ($order->courier_provider_id === null && ! $order->reseller_own_courier) {
                $order->courier_provider_id = CourierProvider::defaultForCompany($order->company_id)?->getKey();
            }

            // Only auto-fill when nobody has already put a real shipping fee
            // on the order. The admin order form always submits a
            // `shipping_zone` of null when it couldn't auto-detect a zone
            // from the customer's address (see ShippingFeeService), even if
            // staff then typed the fee in manually — without the extra
            // shipping_fee check here, that manual entry was silently
            // discarded and replaced with the same failed 0 fee.
            if ($order->shipping_zone === null && (float) ($order->shipping_fee ?? 0) <= 0 && $order->company) {
                $fee = app(ShippingFeeService::class)->feeFor($order->customer?->address, $order->company);
                $order->shipping_zone = $fee['zone'];
                $order->shipping_fee = $fee['fee'];
            }
        });

        static::saving(function (Order $order): void {
            $order->customer_name = $order->customer?->name ?? $order->customer_name;
        });

        static::created(function (Order $order): void {
            // Seeds one OrderPayment row from whatever was typed into the
            // one-field "Paid Amount" input at creation time, so the new
            // Payments History ledger has a starting entry instead of
            // showing an empty history next to a non-zero paid_amount.
            // OrderPayment::saved() calls recalculatePaidAmount() right
            // back, which is a harmless no-op here since the sum already
            // matches what was just submitted.
            if ((float) $order->paid_amount > 0 && Schema::hasTable('order_payments')) {
                $order->payments()->create([
                    'company_id' => $order->company_id,
                    'type' => OrderPayment::TYPE_ADVANCE,
                    'method' => 'cash',
                    'account_id' => $order->initialPaymentAccountId,
                    'amount' => $order->paid_amount,
                    'note' => 'Recorded at order creation.',
                    'paid_at' => $order->order_date ?? now()->toDateString(),
                ]);
            }
        });

        static::updated(function (Order $order): void {
            if (
                $order->wasChanged('status')
                && $order->source === self::SOURCE_WOOCOMMERCE
                && filled($order->external_reference)
                && ! $order->suppressWooCommercePush
            ) {
                PushWooCommerceOrderStatusJob::dispatch($order->getKey());
            }

            // One-way ERP -> website status tracking (the owner's answer:
            // the website only ever displays status, it never pushes one
            // back), so no suppress flag is needed here unlike the
            // WooCommerce push above.
            if ($order->wasChanged(['status', 'delivery_status'])) {
                app(WebsiteWebhookDispatcher::class)->dispatchOrderStatusUpdated($order);
            }

            if (
                ! $order->wasChanged(['status', 'delivery_status'])
                || ! Schema::hasTable('order_status_transitions')
            ) {
                return;
            }

            $order->statusTransitions()->create([
                'company_id' => $order->company_id,
                'changed_by' => $order->statusTransitionActorId ?? Auth::id(),
                'stage' => $order->statusTransitionStage,
                'from_status' => $order->getRawOriginal('status'),
                'to_status' => $order->status,
                'from_delivery_status' => $order->getRawOriginal('delivery_status'),
                'to_delivery_status' => $order->delivery_status,
                'source' => $order->statusTransitionSource,
                'reason' => $order->statusTransitionReason,
            ]);
        });

        static::saved(function (Order $order): void {
            $order->syncTotalsStockAndCustomerBalance();
            app(ResellerCommissionService::class)->sync($order);
            app(OrderWorkflowService::class)->syncPreviousCustomerBalance($order);
            if ($order->wasChanged('status') && $order->isAccounted() && Schema::hasTable('fraud_checks')) {
                app(CustomerRiskService::class)->evaluateOrder($order);
            }
        });

        static::deleted(function (Order $order): void {
            app(OrderWorkflowService::class)->deleteStockMovements($order);
            app(OrderWorkflowService::class)->syncCustomerBalance($order);
        });

        static::restored(function (Order $order): void {
            // Moving an accounted order to trash releases its stock and due
            // contribution in the deleted hook above. Restoring must rebuild
            // those business effects, not only clear the deleted_at column.
            $order->syncTotalsStockAndCustomerBalance();
        });

        static::forceDeleting(function (Order $order): void {
            // Courier bookings intentionally use a restrictive foreign key so
            // they cannot be orphaned accidentally. A confirmed permanent
            // deletion owns that cleanup and lets the booking's child logs and
            // returns follow their existing cascade rules.
            $order->courierBookings()
                ->withoutGlobalScopes()
                ->eachById(fn (CourierBooking $booking): bool => $booking->delete());

            // Order payments go with the order through the database cascade,
            // which skips their model events — take their account-ledger
            // postings out here so the account balances stay correct.
            TransactionLedger::query()
                ->withoutGlobalScopes()
                ->where('reference_type', OrderPayment::class)
                ->whereIn('reference_id', $order->payments()->withoutGlobalScopes()->select('id'))
                ->get()
                ->each
                ->delete();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function costs(): HasMany
    {
        return $this->hasMany(OrderCost::class);
    }

    public function storefrontPayments(): HasMany
    {
        return $this->hasMany(StorefrontPayment::class);
    }

    /** The Next.js site's settings row this order was placed on; null for the Blade storefront. */
    public function storefrontSetting(): BelongsTo
    {
        return $this->belongsTo(StorefrontSetting::class)->withoutGlobalScopes();
    }

    /**
     * The storefront settings of the site this order came from: its own
     * Next.js row, otherwise the company's Blade storefront. Payment, pixel
     * and notification credentials are read from here, never from "the
     * company's" row, so an order is always handled with its own site's keys.
     */
    public function siteSetting(): ?StorefrontSetting
    {
        return $this->storefrontSetting ?? StorefrontSetting::bladeFor((int) $this->company_id);
    }

    public function courierBookings(): HasMany
    {
        return $this->hasMany(CourierBooking::class);
    }

    public function latestCourierBooking()
    {
        return $this->hasOne(CourierBooking::class)->latestOfMany();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The reseller whose storefront this order was placed through (null
     * for the normal, non-reseller storefront). Distinct from customer()
     * above, which is the buyer.
     */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'reseller_customer_id');
    }

    /**
     * The courier this order is assigned to book through — pre-filled from
     * the company's default courier on creation, editable until the order
     * is actually booked. Distinct from `latestCourierBooking()->provider`,
     * which only exists once a booking has actually been made.
     */
    public function courierProvider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class);
    }

    public function vouchers(): BelongsToMany
    {
        return $this->belongsToMany(Voucher::class)->withTimestamps();
    }

    public function fraudChecks(): HasMany
    {
        return $this->hasMany(FraudCheck::class);
    }

    public function riskReviews(): HasMany
    {
        return $this->hasMany(CustomerRiskReview::class);
    }

    public function resellerCommission(): HasOne
    {
        return $this->hasOne(ResellerCommission::class);
    }

    /**
     * Owner rule: a reseller's customer never sees the store's price. On an
     * own-courier order the amounts on the order are what the reseller pays
     * the store, so anything shown to the buyer (invoice, courier slip,
     * order tracking, the buyer's account, the summary card) uses these.
     */
    public function showsResellerPricesToBuyer(): bool
    {
        return (bool) $this->reseller_own_courier;
    }

    /**
     * Owner rules (2026-10-07): every reseller order is invoiced in the
     * reseller's own store name and logo, never the company's -- orders the
     * reseller placed from the portal (store-delivered or own courier) and
     * orders their customers placed on the reseller's store. Prices follow
     * showsResellerPricesToBuyer() separately: apart from own-courier
     * orders, the amounts on the order already are the reseller's prices.
     */
    public function showsResellerBranding(): bool
    {
        return $this->reseller_customer_id !== null;
    }

    /** The order total in the buyer's prices (the reseller's retail prices on an own-courier order). */
    public function buyerTotal(): float
    {
        if (! $this->showsResellerPricesToBuyer()) {
            return (float) $this->total_amount;
        }

        return round($this->items->sum(fn (OrderItem $item): float => $item->buyerSubtotal()), 2);
    }

    /** The reseller's latest advance payment for an own-courier order (a rejected one can be sent again). */
    public function resellerAdvancePayment(): HasOne
    {
        return $this->hasOne(StorefrontPayment::class)
            ->where('purpose', StorefrontPayment::PURPOSE_RESELLER_ADVANCE)
            ->latestOfMany();
    }

    /**
     * Every advance payment of an own-courier order: the first one for the
     * whole order, then one for the extra each time the reseller's edit
     * raised the total (ResellerOwnCourierService).
     */
    public function resellerAdvancePayments(): HasMany
    {
        return $this->hasMany(StorefrontPayment::class)
            ->where('purpose', StorefrontPayment::PURPOSE_RESELLER_ADVANCE)
            ->orderBy('id');
    }

    public function statusTransitions(): HasMany
    {
        return $this->hasMany(OrderStatusTransition::class);
    }

    public function latestRiskReview()
    {
        return $this->hasOne(CustomerRiskReview::class)->latestOfMany();
    }

    public function latestFraudCheck()
    {
        return $this->hasOne(FraudCheck::class)->latestOfMany();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function sequentialNumberColumn(): string
    {
        return 'order_number';
    }

    /**
     * `PREFIX-YYYYMMDD-NN`, where the trailing number is a single running
     * counter per company that does NOT reset each day -- it is the company's
     * total order count so far, plus one. So two orders placed today read
     * `...-01`, `...-02`, and tomorrow's next order is `...-03` rather than
     * restarting at `...-01`. The date segment is the placement date, kept for
     * readability only; the running number is what makes an invoice findable.
     * Padded to a minimum of two digits and grows naturally past 99.
     *
     * Counting rows (rather than parsing the largest existing suffix) keeps
     * this database-agnostic and means every order advances the counter --
     * including admin orders that submit the pre-filled number from the form.
     * A concurrent insert that grabs the same number collides on the
     * `order_number` UNIQUE index and GeneratesSequentialNumber retries, at
     * which point the now-committed row makes the recount land one higher.
     */
    public static function nextOrderNumber(?Company $company = null): string
    {
        $company ??= app()->bound('company.context') ? app('company.context')->company() : null;
        $company ??= Company::defaultCompany();
        $prefix = $company?->invoice_prefix ?: 'INV';

        // withoutGlobalScopes(): count for *this* company regardless of the
        // active company context, and include trashed orders -- a trashed
        // order still consumed a number and must not have it re-minted.
        $sequence = self::query()
            ->withoutGlobalScopes()
            ->when($company, fn ($query) => $query->where('company_id', $company->getKey()))
            ->count() + 1;

        return $prefix.'-'.now()->format('Ymd').'-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    public function syncTotalsStockAndCustomerBalance(): void
    {
        app(OrderWorkflowService::class)->sync($this);
    }

    public function syncCustomerBalance(): void
    {
        app(OrderWorkflowService::class)->syncCustomerBalance($this);
    }

    /**
     * Keeps paid_amount/due_amount in sync with the sum of this order's
     * OrderPayment ledger rows — the same forceFill+saveQuietly pattern
     * OrderWorkflowService::sync() and Customer::syncCurrentBalance()
     * already use, so this never re-triggers the `saved` observer above.
     * Called from OrderPayment's own booted() hooks whenever a payment row
     * is added, edited, or deleted.
     */
    public function recalculatePaidAmount(): void
    {
        $paid = (float) $this->payments()->sum('amount');
        $due = max((float) $this->total_amount - $paid, 0);

        if ((float) $this->paid_amount != $paid || (float) $this->due_amount != $due) {
            $this->forceFill([
                'paid_amount' => $paid,
                'due_amount' => $due,
            ])->saveQuietly();
        }
    }

    /**
     * Order-level cost total from the manual Associated Costs ledger
     * (OrderCost — courier delivery cost, COD charge, purchase cost,
     * other). Does not include OrderItem::unit_cost (per-line COGS),
     * which profit() sums separately.
     */
    public function totalCost(): float
    {
        return (float) $this->costs()->sum('amount');
    }

    /**
     * total_amount minus both cost layers: per-line COGS
     * (OrderItem::unit_cost x quantity, the product's own cost basis) and
     * the manual Associated Costs ledger. Mirrors Nuport's order-level
     * profitability view, which this app didn't otherwise surface.
     */
    public function profit(): float
    {
        $cogs = $this->items()->get()
            ->sum(fn (OrderItem $item): float => (int) $item->quantity * (float) $item->unit_cost);

        return (float) $this->total_amount - $cogs - $this->totalCost();
    }

    public function isAccounted(): bool
    {
        return in_array($this->status, self::ACCOUNTED_STATUSES, true);
    }

    /**
     * What a courier should collect on delivery: the full invoice total
     * (products − discount + VAT + shipping), never reduced by an advance
     * already paid. If the order carries no shipping fee of its own, the zone
     * delivery charge for the customer's address is added on top so the COD
     * always includes delivery (owner rule).
     */
    public function courierCodAmount(): float
    {
        $this->loadMissing('customer');

        $total = (float) $this->total_amount;

        if ((float) $this->shipping_fee <= 0 && $this->company) {
            $total += (float) app(ShippingFeeService::class)
                ->feeFor($this->customer?->address, $this->company)['fee'];
        }

        return round(max($total, 0), 2);
    }

    /**
     * Why this order cannot be booked with a courier, or null when it can.
     *
     * "Already booked" means a courier booking that is still live — not the
     * Delivery status field, which can say Booked / In transit without any
     * booking (set on the Create order form, or by moving the order to
     * Shipping through Change status). Such an order used to be reported as
     * already booked and could never be booked. When the latest booking was
     * cancelled or failed, a new one can be made.
     */
    public function courierBookingBlocker(): ?string
    {
        if ($this->reseller_own_courier) {
            return __('The reseller ships this order with their own courier.');
        }

        if (in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_RETURNED, self::STATUS_REFUNDED], true)) {
            return __('The order is :status.', ['status' => __(self::STATUSES[$this->status] ?? $this->status)]);
        }

        if (in_array($this->delivery_status, [
            CourierBooking::STATUS_DELIVERED,
            CourierBooking::STATUS_PARTIAL_DELIVERED,
            CourierBooking::STATUS_RETURNED,
        ], true)) {
            return __('The order is :status.', ['status' => __(self::DELIVERY_STATUSES[$this->delivery_status])]);
        }

        // The orders list eager-loads latestCourierBooking.
        $liveBooking = $this->latestCourierBooking;

        if ($liveBooking && ! in_array($liveBooking->status, [CourierBooking::STATUS_CANCELLED, CourierBooking::STATUS_FAILED], true)) {
            return __('Already booked (tracking ID :tracking).', ['tracking' => $liveBooking->tracking_id ?: '—']);
        }

        return null;
    }

    public function workflowStage(): string
    {
        if (in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_RETURNED, self::STATUS_REFUNDED], true)) {
            return $this->status;
        }

        if (in_array($this->delivery_status, [
            CourierBooking::STATUS_PICKED_UP,
            CourierBooking::STATUS_IN_TRANSIT,
            CourierBooking::STATUS_PARTIAL_DELIVERED,
        ], true)) {
            return self::STAGE_SHIPPING;
        }

        return $this->status;
    }

    /** @return list<string> */
    public function allowedWorkflowStages(): array
    {
        return self::WORKFLOW_TRANSITIONS[$this->workflowStage()] ?? [];
    }

    public function allowedWorkflowStageOptions(): array
    {
        return collect($this->allowedWorkflowStages())
            ->mapWithKeys(fn (string $stage): array => [$stage => self::WORKFLOW_STAGES[$stage]])
            ->all();
    }

    public function clearStatusTransitionContext(): void
    {
        $this->statusTransitionStage = null;
        $this->statusTransitionReason = null;
        $this->statusTransitionSource = 'direct';
        $this->statusTransitionActorId = null;
    }
}
