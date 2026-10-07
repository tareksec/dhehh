<?php

namespace App\Models\Concerns;

use App\Services\WebsiteWebhookDispatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Tells the company's website (the Next.js site's cache) that storefront
 * content changed — settings (home page, header, footer, menus), hero slides,
 * pages, categories, offers/collections — so an edit in the dashboard shows
 * up in seconds instead of when the site's safety-net cache expires. A silent
 * no-op when no website webhook is configured (see WebsiteWebhookDispatcher).
 */
trait NotifiesWebsiteOfStorefrontChanges
{
    protected static function bootNotifiesWebsiteOfStorefrontChanges(): void
    {
        $notify = static function (Model $model): void {
            app(WebsiteWebhookDispatcher::class)->dispatchStorefrontUpdated($model->getAttribute('company_id'));
        };

        static::saved($notify);
        static::deleted($notify);
    }
}
