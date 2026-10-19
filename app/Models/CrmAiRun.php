<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmAiRun extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['is_test' => 'boolean', 'meta' => 'array', 'estimated_cost_usd' => 'decimal:6', 'resolved_at' => 'datetime'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
