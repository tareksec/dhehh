<?php

namespace App\Http\Controllers\Reseller;

use App\Filament\Reseller\Pages\Orders;
use App\Http\Controllers\Controller;
use App\Models\StorefrontPayment;
use App\Services\CompanyContext;
use App\Services\ResellerOwnCourierService;
use App\Services\StorefrontPaymentService;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where the online gateway sends a reseller back after paying an own-courier
 * advance (owner decision 2026-10-14). The signed link proves it is the
 * payment this portal started; the gateway itself is asked whether the
 * money arrived, so nothing on the query string is trusted. The webhook does
 * the same on its own, so the payment is entered even if the reseller never
 * comes back.
 */
class ResellerOnlinePaymentReturnController extends Controller
{
    public function __invoke(Request $request, StorefrontPayment $payment, StorefrontPaymentService $payments): RedirectResponse
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(['invoice_number', 'trx_id', 'status', 'cancel']), 403);

        $company = app(CompanyContext::class)->company();

        abort_unless(
            $company !== null
            && (int) $payment->company_id === (int) $company->getKey()
            && $payment->purpose === StorefrontPayment::PURPOSE_RESELLER_ADVANCE
            && app(ResellerOwnCourierService::class)->isOnline($payment),
            404,
        );

        try {
            $payments->verifyAndFinalize($payment, $payment->siteSetting(), transactionId: $request->query('trx_id'));
        } catch (\Throwable $exception) {
            Log::warning('Reseller online advance could not be verified on return', ['payment' => $payment->getKey(), 'error' => $exception->getMessage()]);
        }

        $notification = Notification::make();

        if ($payment->refresh()->status === StorefrontPayment::STATUS_COMPLETED) {
            $notification->title(__('Payment received. The store will pack your parcel.'))->success();
        } else {
            $notification->title(__('The payment was not completed. Use "Pay now" on the order to try again.'))->warning();
        }

        $notification->send();

        return redirect()->to(Orders::getUrl(panel: 'reseller'));
    }
}
