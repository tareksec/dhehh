<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One reseller's margin on one order: (reseller selling price - reseller
 * base price) x quantity, summed over the order's reseller-priced lines.
 * Kept in sync with the order by ResellerCommissionService; becomes
 * payable only once the order is delivered (owner's rule).
 */
class ResellerCommission extends Model
{
    use BelongsToCompany;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAYABLE = 'payable';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending delivery',
        self::STATUS_PAYABLE => 'Payable',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'company_id',
        'order_id',
        'customer_id',
        'sales_amount',
        'base_amount',
        'amount',
        'status',
        'payable_at',
    ];

    protected function casts(): array
    {
        return [
            'sales_amount' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'payable_at' => 'datetime',
        ];
    }

    /**
     * SQL expression turning a datetime column into a "YYYY-MM" bucket, for
     * the portal's monthly totals -- DB-portable because the test suite runs
     * on sqlite while production is MySQL/Postgres.
     */
    public static function monthExpression(string $column = 'created_at'): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "date_format({$column}, '%Y-%m')",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "strftime('%Y-%m', {$column})",
        };
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
