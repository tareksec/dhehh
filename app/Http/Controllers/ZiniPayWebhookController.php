<?php

namespace App\Http\Controllers;

use App\Models\StorefrontPayment;
use App\Services\StorefrontPaymentService;
use App\Services\ZiniPayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZiniPayWebhookController extends Controller
{
    public function __invoke(Request $request, StorefrontPayment $payment, StorefrontPaymentService $payments): JsonResponse
    {
        $invoiceId = trim((string) ($request->input('invoice_id') ?: $payment->invoice_id));

        if ($invoiceId === '') {
            return response()->json(['ok' => true]);
        }

        $setting = $payment->siteSetting();

        if (! $setting || ! ZiniPayClient::isConfigured($setting)) {
            return response()->json(['ok' => false], 422);
        }

        try {
            $payments->verifyAndFinalize($payment, $setting, $invoiceId);
        } catch (\Throwable $exception) {
            Log::warning('ZiniPay webhook verification failed', ['payment' => $payment->getKey(), 'error' => $exception->getMessage()]);

            return response()->json(['ok' => false], 502);
        }

        return response()->json(['ok' => true]);
    }
}
