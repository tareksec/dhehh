<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeadActivity extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'call' => 'Call',
        'message' => 'Message',
        'note' => 'Note',
        'meeting' => 'Meeting',
        'status_change' => 'Status Change',
    ];

    protected $fillable = ['lead_id', 'user_id', 'type', 'note', 'next_action_at'];

    protected $casts = ['next_action_at' => 'datetime'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
