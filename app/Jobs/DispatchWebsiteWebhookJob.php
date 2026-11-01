<?php

namespace App\Jobs;

use App\Support\OutboundUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends one signed webhook delivery to an external website — queued (not
 * synchronous from the model hook that triggered it) so a slow/unreachable
 * website never blocks saving a Product/StockMovement/Order. Signing mirrors
 * WooCommerceWebhookController's inbound HMAC check, just in the outbound
 * direction: X-ZamZam-Signature is hash_hmac('sha256', <raw JSON body>, secret).
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
    ) {}

    public function handle(): void
    {
        $body = json_encode([
            'event' => $this->event,
            'data' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ]);

        $signature = hash_hmac('sha256', $body, $this->secret);

        try {
            $response = OutboundUrlGuard::http($this->url, timeout: 10)
                ->retry(2, 1000)
                ->withHeaders(['X-ZamZam-Signature' => $signature])
                ->withBody($body, 'application/json')
                ->post($this->url);
        } catch (\Throwable $exception) {
            Log::warning('Website webhook delivery failed.', [
                'url' => $this->url,
                'event' => $this->event,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($response->failed()) {
            Log::warning('Website webhook delivery rejected.', [
                'url' => $this->url,
                'event' => $this->event,
                'status' => $response->status(),
            ]);
        }
    }
}
