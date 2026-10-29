<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Product;
use App\Models\WebsiteWebhookDelivery;
use App\Services\CompanyContext;
use App\Support\WebsiteProductPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends one signed webhook delivery to an external website — queued (not
 * synchronous from the model hook that triggered it) so a slow/unreachable
 * website never blocks saving a Product/StockMovement/Order. Signing mirrors
 * WooCommerceWebhookController's inbound HMAC check, just in the outbound
 * direction: X-ZamZam-Signature is hash_hmac('sha256', <raw JSON body>, secret).
 *
 * Every delivery with a known company is recorded as a WebsiteWebhookDelivery
 * (Settings → Integrations → Website API). With $productId set, the product
 * payload is rebuilt from the database at send time, so a delayed
 * product.created carries the product as it is once the form finished saving
 * (stock, gallery), not as it was at the first insert.
 */
class DispatchWebsiteWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $url,
        public string $secret,
        public string $event,
        public array $data,
        public ?int $companyId = null,
        public ?int $productId = null,
    ) {}

    public function handle(): void
    {
        $company = $this->companyId ? Company::query()->find($this->companyId) : null;

        if ($company) {
            $proceed = app(CompanyContext::class)->within($company, fn (): bool => $this->refreshProductPayload());

            if (! $proceed) {
                return;
            }
        }

        $body = json_encode([
            'event' => $this->event,
            'data' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ]);

        $signature = hash_hmac('sha256', $body, $this->secret);
        $attempts = 0;

        try {
            $response = Http::timeout(10)
                ->retry(2, 1000, throw: false)
                ->beforeSending(function () use (&$attempts): void {
                    $attempts++;
                })
                ->withHeaders(['X-ZamZam-Signature' => $signature])
                ->withBody($body, 'application/json')
                ->post($this->url);
        } catch (\Throwable $exception) {
            Log::warning('Website webhook delivery failed.', [
                'url' => $this->url,
                'event' => $this->event,
                'error' => $exception->getMessage(),
            ]);

            $this->record(null, $attempts, $exception->getMessage());

            return;
        }

        if ($response->failed()) {
            Log::warning('Website webhook delivery rejected.', [
                'url' => $this->url,
                'event' => $this->event,
                'status' => $response->status(),
            ]);
        }

        $this->record($response, $attempts);
    }

    /**
     * False when the product no longer exists (deleted before a delayed
     * product.created went out) — its product.deleted delivery covers it.
     */
    protected function refreshProductPayload(): bool
    {
        if (! $this->productId) {
            return true;
        }

        $product = Product::query()->find($this->productId);

        if (! $product) {
            return false;
        }

        $this->data = [
            ...WebsiteProductPayload::for($product),
            'changed_fields' => $this->data['changed_fields'] ?? [],
        ];

        return true;
    }

    protected function record(?Response $response, int $attempts, ?string $error = null): void
    {
        if (! $this->companyId) {
            return;
        }

        rescue(fn () => WebsiteWebhookDelivery::query()->create([
            'company_id' => $this->companyId,
            'event' => $this->event,
            'sku' => isset($this->data['sku']) ? Str::limit((string) $this->data['sku'], 187) : null,
            'url' => Str::limit($this->url, 252),
            'status' => $response?->successful() ? WebsiteWebhookDelivery::STATUS_DELIVERED : WebsiteWebhookDelivery::STATUS_FAILED,
            'response_status' => $response?->status(),
            'attempts' => max(1, $attempts),
            'response_excerpt' => $response ? Str::limit(trim($response->body()), 500) : null,
            'error' => $error ? Str::limit($error, 1000) : null,
            'payload' => $this->data,
        ]), report: true);
    }
}
