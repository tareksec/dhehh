<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Services\WooCommerceOrderSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives WooCommerce's native order.created/order.updated/order.deleted
 * webhooks (WordPress admin: WooCommerce → Settings → Advanced → Webhooks)
 * and hands the payload to WooCommerceOrderSyncService. See
 * 09_DASHBOARD_WOOCOMMERCE_SECURITY_PLAN.md step 2 for the design.
 */
class WooCommerceWebhookController extends Controller
{
    public function __invoke(Request $request, Company $company, WooCommerceOrderSyncService $sync): JsonResponse
    {
        $setting = StorefrontSetting::primaryFor((int) $company->getKey());
        $secret = (string) data_get($setting?->woocommerce_credentials, 'webhook_secret');

        if ($secret === '') {
            Log::warning('WooCommerce webhook rejected: no webhook secret is saved for this company.', [
                'company' => $company->getKey(),
            ]);

            // A plain abort(404) leaves WooCommerce's own delivery log
            // showing nothing but the status code — the owner has no way to
            // see *why* without server access. This body is safe to expose
            // (no secret, no signature): it turns WooCommerce's "Recent
            // Deliveries" → response tab into a self-serve diagnostic.
            return response()->json([
                'ok' => false,
                'error' => 'no_webhook_secret_saved',
                'hint' => 'No webhook secret is saved for this company in ZamZam ERP yet. Go to Settings → Integrations → WooCommerce, generate one, save changes, then paste it into this webhook\'s Secret field.',
            ], 404);
        }

        $signature = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
        $receivedSignature = (string) $request->header('X-WC-Webhook-Signature');

        // Neither value below is the secret itself (both are one-way HMAC
        // digests) — safe to log in full for diagnosing a real mismatch,
        // e.g. WooCommerce's Secret field not exactly matching what's saved
        // here (Settings → Integrations → WooCommerce → "Send test webhook"
        // checks that specific possibility in one click).
        if (! hash_equals($signature, $receivedSignature)) {
            Log::warning('WooCommerce webhook rejected: signature does not match the secret saved for this company.', [
                'company' => $company->getKey(),
                'topic' => (string) $request->header('X-WC-Webhook-Topic'),
                'signature_header_present' => $receivedSignature !== '',
                'computed_signature' => $signature,
                'received_signature' => $receivedSignature,
            ]);

            // Same reasoning as the 404 above: expose enough in the response
            // body (still nothing secret) for the owner to tell, from
            // WooCommerce's own delivery log alone, whether the signature
            // header even arrived versus a genuine secret mismatch.
            return response()->json([
                'ok' => false,
                'error' => 'signature_mismatch',
                'hint' => 'This webhook\'s Secret field does not match the "Order webhook secret" saved in ZamZam ERP → Settings → Integrations → WooCommerce. Re-copy it fresh (click the eye icon, select all, copy) into this webhook\'s Secret field and save again.',
                'signature_header_present' => $receivedSignature !== '',
                'request_body_length' => strlen($request->getContent()),
            ], 403);
        }

        $topic = (string) $request->header('X-WC-Webhook-Topic');
        $payload = (array) $request->json()->all();

        // WooCommerce sends a harmless ping payload (no "id") when a webhook
        // is first created — nothing to sync, just acknowledge it.
        if (blank($payload)) {
            return response()->json(['ok' => true]);
        }

        try {
            match (true) {
                str_starts_with($topic, 'order.') => $sync->handleOrderEvent($company, $topic, $payload),
                default => Log::info('WooCommerce webhook received for an unhandled topic.', [
                    'company' => $company->getKey(),
                    'topic' => $topic,
                ]),
            };
        } catch (\Throwable $exception) {
            Log::warning('WooCommerce webhook processing failed', [
                'company' => $company->getKey(),
                'topic' => $topic,
                'error' => $exception->getMessage(),
            ]);

            // Non-2xx makes WooCommerce retry the delivery later.
            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }
}
