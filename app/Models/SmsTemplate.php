<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One company's wording for one SMS (see App\Services\SmsTemplateService for
 * the list of messages). An empty body means "no custom wording".
 */
class SmsTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'key', 'body'];
}
