<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A saved text snippet staff insert into an Inbox reply - the WhatsApp
 * Business App's own "Quick Replies" feature, reimplemented here so it
 * works the same way from the ERP Inbox. See CompanyFaq for the identical
 * shape this mirrors.
 */
class QuickReply extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $table = 'company_quick_replies';

    protected $fillable = ['company_id', 'shortcut', 'body', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
