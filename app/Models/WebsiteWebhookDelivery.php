<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One outbound website webhook delivery (see DispatchWebsiteWebhookJob),
 * listed on Settings → Integrations → Website API. `payload` is the `data`
 * that was sent, kept so a failed delivery can be sent again; the signing
 * secret is never stored here — a resend reads it from the site's settings.
 */
class WebsiteWebhookDelivery extends Model
{
    use BelongsToCompany;

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'event',
        'sku',
        'url',
        'status',
        'response_status',
        'attempts',
        'response_excerpt',
        'error',
        'payload',
    ];

    protected $casts = [
        'response_status' => 'integer',
        'attempts' => 'integer',
        'payload' => 'array',
    ];
}
