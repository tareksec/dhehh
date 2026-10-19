<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmSalesFollowUp extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['due_at' => 'datetime', 'sent_at' => 'datetime', 'base_at' => 'datetime', 'step' => 'integer'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
