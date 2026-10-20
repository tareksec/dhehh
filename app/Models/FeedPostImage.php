<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedPostImage extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'post_id', 'path', 'sort'];

    protected $hidden = ['path'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(FeedPost::class, 'post_id');
    }
}
