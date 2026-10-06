<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StorefrontPayment extends Model
{
    use BelongsToCompany;

    public const PURPOSE_ORDER_PAYMENT = 'order_payment';

    public const PURPOSE_PREORDER_ADVANCE = 'preorder_advance';

    public const PURPOSE_NEW_CUSTOMER_DELIVERY = 'new_customer_delivery';

    public const PURPOSE_CHECKOUT_ADVANCE = 'checkout_advance';

    /** A reseller's full advance payment for an order sent with their own courier. */
    public const PURPOSE_RESELLER_ADVANCE = 'reseller_advance';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_FAILED => 'Failed',
    ];

    protected $fillable = [
        'company_id',
        'order_id',
        'gateway',
        'storefront_payment_method_id',
        'purpose',
        'invoice_id',
        'amount',
        'status',
        'payment_method',
        'transaction_id',
        'payload',
        'checkout_data',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payload' => 'array',
        'checkout_data' => 'encrypted:array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(StorefrontPaymentMethod::class, 'storefront_payment_method_id');
    }
}
