<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCourierWebhook;
use App\Models\CourierProvider;
use App\Models\CourierWebhookLog;
use App\Services\CourierManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourierWebhookController extends Controller
{
    /** Larger bodies are not kept on a rejected call — nobody vouched for them. */
    protected const REJECTED_PAYLOAD_LIMIT = 16384;

    public function __invoke(Request $request, CourierProvider $provider, CourierManager $couriers): JsonResponse
    {
        abort_unless($provider->is_active, 404);
        $adapter = $couriers->adapter($provider);

        if (! $adapter->verifyWebhookRequest($provider, $request)) {
            // A call with a missing or wrong token used to leave no trace,
            // so a Callback URL / Auth token mismatch looked exactly like
            // the courier never calling. Record it (once per body) so the
            // Webhook Logs page shows the courier is calling and why it
            // was turned away.
            $this->logRejected($request, $provider);

            abort(401);
        }

        $payload = $request->json()->all();
        // Steadfast resends a retried event with the same Idempotency-Key.
        $deliveryId = (string) ($request->header('X-Webhook-Id')
            ?: $request->header('Idempotency-Key')
            ?: ($payload['event_id'] ?? hash('sha256', $request->getContent())));

        $log = CourierWebhookLog::withoutGlobalScopes()->firstOrCreate(
            ['courier_provider_id' => $provider->getKey(), 'delivery_id' => $deliveryId],
            [
                'company_id' => $provider->company_id,
                'event' => $payload['event'] ?? $payload['notification_type'] ?? $payload['status'] ?? null,
                'payload' => $payload,
                'status' => 'pending',
            ],
        );

        if ($log->wasRecentlyCreated) {
            ProcessCourierWebhook::dispatch($log->getKey());
        }

        // Steadfast's webhook guide answers a received call with 200 and
        // this body; the other couriers keep the plain 202.
        if ($provider->driver === CourierProvider::DRIVER_STEADFAST) {
            return response()->json(['status' => 'success', 'message' => 'Webhook received successfully.']);
        }

        return response()->json(['accepted' => true], 202);
    }

    protected function logRejected(Request $request, CourierProvider $provider): void
    {
        $body = $request->getContent();
        $payload = strlen($body) <= self::REJECTED_PAYLOAD_LIMIT ? json_decode($body, true) : null;
        $header = (string) ($provider->settings['signature_header'] ?? app(CourierManager::class)->adapter($provider)->signatureHeaderDefault());

        $reason = match (true) {
            blank($provider->credentials['webhook_secret'] ?? null) => 'Rejected: no Webhook Auth Token is saved on this provider. Generate one on the provider page and paste the same value into the courier\'s webhook settings.',
            blank($request->header($header)) => "Rejected: the call had no {$header} token. Set the Auth token in the courier's webhook settings to the provider's Webhook Auth Token.",
            default => 'Rejected: the token (or its X-Signature) does not match this provider\'s Webhook Auth Token. Paste the exact same token on both sides.',
        };

        CourierWebhookLog::withoutGlobalScopes()->firstOrCreate(
            ['courier_provider_id' => $provider->getKey(), 'delivery_id' => 'rejected:'.hash('sha256', $body)],
            [
                'company_id' => $provider->company_id,
                'event' => is_array($payload) ? ($payload['event'] ?? $payload['notification_type'] ?? $payload['status'] ?? null) : null,
                'payload' => is_array($payload) ? $payload : [],
                'status' => 'rejected',
                'error' => $reason,
            ],
        );
    }
}
