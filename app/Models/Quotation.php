<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GeneratesSequentialNumber;
use App\Services\Crm\SalesFollowUpService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use BelongsToCompany, GeneratesSequentialNumber;

    public const STATUSES = [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
    ];

    protected $fillable = [
        'company_id', 'lead_id', 'customer_id', 'quotation_number', 'status',
        'valid_until', 'discount_amount', 'total_amount', 'converted_order_id', 'created_by',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Quotation $quotation): void {
            $quotation->quotation_number ??= static::nextQuotationNumber();
            $quotation->status ??= 'draft';
        });
        static::updated(function (Quotation $quotation): void {
            if ($quotation->wasChanged('status') && $quotation->status === 'sent' && $quotation->lead_id) {
                $conversation = Conversation::query()->where('company_id', $quotation->company_id)->where('lead_id', $quotation->lead_id)->latest('last_message_at')->first();
                if ($conversation) {
                    app(SalesFollowUpService::class)->schedule($conversation, quotation: $quotation);
                }
            }
        });
    }

    protected function sequentialNumberColumn(): string
    {
        return 'quotation_number';
    }

    public static function nextQuotationNumber(): string
    {
        $base = 'QT-'.now()->format('Ymd').'-';
        $lastNumber = self::query()
            ->withoutGlobalScopes()
            ->where('quotation_number', 'like', $base.'%')
            // Length-first keeps the sort numerically correct once the daily
            // sequence passes 9999 ("...-10000" would otherwise rank below
            // "...-9999" under a plain string ORDER BY).
            ->orderByRaw('LENGTH(quotation_number) desc')
            ->orderByDesc('quotation_number')
            ->value('quotation_number');

        $sequence = $lastNumber ? ((int) substr($lastNumber, -4)) + 1 : 1;

        return $base.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }

    public function recalculateTotal(): void
    {
        $itemsTotal = (float) $this->items()->sum('subtotal');
        $total = $itemsTotal - (float) $this->discount_amount;

        if ((float) $this->total_amount !== $total) {
            $this->forceFill(['total_amount' => $total])->saveQuietly();
        }
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast() && $this->status === 'sent';
    }
}
