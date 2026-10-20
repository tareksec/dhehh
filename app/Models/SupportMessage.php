<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessage extends Model
{
    use BelongsToCompany;

    // created_at only so old support requests keep their date when copied in.
    protected $fillable = ['company_id', 'thread_id', 'body', 'from_staff', 'user_id', 'created_at'];

    protected $casts = ['from_staff' => 'boolean'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(SupportThread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
