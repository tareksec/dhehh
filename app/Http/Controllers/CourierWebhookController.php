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
    public function __invoke(Request $request, CourierProvider $provider, CourierManager $couriers): JsonResponse
    {
        abort_unless($provider->is_active, 404);
        $adapter = $couriers->adapter($provider);
        abort_unless($adapter->verifyWebhookRequest($provider, $request), 401);

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

        return response()->json(['accepted' => true], 202);
    }
}
