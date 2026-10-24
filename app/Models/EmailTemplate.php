<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One company's wording for one email (see App\Services\EmailTemplateService
 * for the list of built-in messages). A row with an empty body means "no
 * custom wording"; a custom row (is_custom) is a template the owner made up
 * herself and sends by hand from an order.
 */
class EmailTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'key', 'name', 'subject', 'body', 'is_custom'];

    protected function casts(): array
    {
        return ['is_custom' => 'boolean'];
    }
}
