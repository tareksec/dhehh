<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** The monthly statement went to this investor for this month (so it never goes twice, FR-65). */
class StatementDispatch extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'investor_id', 'period', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];
}
