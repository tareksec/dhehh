<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\OrderStatusEmailService;
use App\Services\OrderStatusSmsService;
use App\Services\OrderWhatsAppCardService;
use App\Services\StorefrontMetaDispatchService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OrderStatusTransition extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'order_id',
        'changed_by',
        'stage',
        'from_status',
        'to_status',
        'from_delivery_status',
        'to_delivery_status',
        'source',
        'reason',
    ];

    protected static function booted(): void
    {
        static::created(function (OrderStatusTransition $transition): void {
            // The customer's status SMS (SMS Gateway → Message templates); a failure here must never undo the status change.
            try {
                if (Schema::hasTable('sms_templates')) {
                    app(OrderStatusSmsService::class)->dispatchForTransition($transition);
                }
            } catch (Throwable $exception) {
                report($exception);
            }

            // The customer's status email (Email Templates); same rule: never undo the change.
            try {
                if (Schema::hasTable('email_templates')) {
                    app(OrderStatusEmailService::class)->dispatchForTransition($transition);
                }
            } catch (Throwable $exception) {
                report($exception);
            }

            // The summary card on WhatsApp (Settings → Order WhatsApp card); same rule: never undo the change.
            try {
                app(OrderWhatsAppCardService::class)->dispatchForTransition($transition);
            } catch (Throwable $exception) {
                report($exception);
            }

            if (! Schema::hasColumn('storefront_settings', 'meta_status_events_enabled')) {
                return;
            }

            app(StorefrontMetaDispatchService::class)->dispatchForTransition($transition);
        });
    }

    /**
     * The order went back to an earlier open stage (Shipping to Processing, Processing to Confirmed...), a
     * correction the customer is not told about: no SMS, no e-mail, no WhatsApp card, no Meta event.
     */
    public function isStepBack(): bool
    {
        $from = Order::openStageRank($this->from_status, $this->from_delivery_status);
        $to = Order::openStageRank($this->to_status, $this->to_delivery_status);

        return $from !== null && $to !== null && $to < $from;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
