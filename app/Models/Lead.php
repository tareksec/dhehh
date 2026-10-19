<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Crm\AttentionQueueService;
use App\Services\WebsiteWebhookDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use BelongsToCompany;

    public const SOURCES = [
        'facebook' => 'Facebook',
        'whatsapp' => 'WhatsApp',
        'website' => 'Website',
        'referral' => 'Referral',
        'walk_in' => 'Walk-in',
        'phone_call' => 'Phone Call',
        'other' => 'Other',
    ];

    public const STATUSES = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'quoted' => 'Quoted',
        'won' => 'Won',
        'lost' => 'Lost',
    ];

    protected $fillable = [
        'company_id', 'name', 'phone', 'email', 'source', 'external_reference', 'status',
        'interest', 'estimated_value', 'assigned_to', 'next_follow_up_at',
        'follow_up_reminded_at', 'opted_out_at', 'converted_customer_id', 'converted_order_id',
        'note', 'created_by',
        'qualification', 'temperature', 'qualification_score', 'qualification_summary',
        'temperature_locked',
    ];

    protected $casts = [
        'next_follow_up_at' => 'datetime',
        'follow_up_reminded_at' => 'datetime',
        'opted_out_at' => 'datetime',
        'estimated_value' => 'decimal:2',
        'qualification' => 'array',
        'qualification_score' => 'integer',
        'temperature_locked' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (Lead $lead): void {
            // Moving the follow-up date means "remind me again" -- never let
            // a stale reminded_at from the old date suppress the new one.
            if ($lead->isDirty('next_follow_up_at') && ! $lead->isDirty('follow_up_reminded_at')) {
                $lead->follow_up_reminded_at = null;
            }
        });

        // One-way ERP -> website quote status tracking, same as
        // Order::booted()'s order.status_updated. Only leads the website
        // itself submitted (they carry its external_reference) are sent —
        // the website has no record of any other lead.
        static::updated(function (Lead $lead): void {
            if ($lead->wasChanged('status') && filled($lead->external_reference)) {
                app(WebsiteWebhookDispatcher::class)->dispatchLeadStatusUpdated($lead);
            }

            // A website lead waiting on the Needs attention list is answered
            // as soon as someone moves it past "new".
            if ($lead->wasChanged('status') && $lead->getOriginal('status') === 'new') {
                app(AttentionQueueService::class)->leadWorked($lead, auth()->user());
            }
        });
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }

    public function isConverted(): bool
    {
        return $this->status === 'won' && $this->converted_customer_id !== null;
    }
}
