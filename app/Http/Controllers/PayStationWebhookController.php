<?php

namespace App\Http\Controllers;

use App\Models\StorefrontPayment;
use App\Services\PayStationClient;
use App\Services\StorefrontPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Defensive fallback mirroring ZiniPayWebhookController exactly. No public
 * source confirmed a real server-to-server IPN for PayStation (its
 * documented flow is browser-redirect-then-verify via CheckoutController's
 * paymentReturn()), but this costs nothing to have in place in case one
 * exists in practice or gets added later.
 */
class PayStationWebhookController extends Controller
{
    public function __invoke(Request $request, StorefrontPayment $payment, StorefrontPaymentService $payments): JsonResponse
    {
        $invoiceId = trim((string) ($request->input('invoice_number') ?: $request->input('invoice_id') ?: $payment->invoice_id));
        $transactionId = $request->input('trx_id');

        if ($invoiceId === '') {
            return response()->json(['ok' => true]);
        }

        $setting = $payment->siteSetting();

        if (! $setting || ! PayStationClient::isConfigured($setting)) {
            return response()->json(['ok' => false], 422);
        }

        try {
            $payments->verifyAndFinalize($payment, $setting, $invoiceId, $transactionId);
        } catch (\Throwable $exception) {
            Log::warning('PayStation webhook verification failed', ['payment' => $payment->getKey(), 'error' => $exception->getMessage()]);

            return response()->json(['ok' => false], 502);
        }

        return response()->json(['ok' => true]);
    }
}
