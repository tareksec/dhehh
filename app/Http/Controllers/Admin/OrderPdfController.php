<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CompanySettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderPdfController extends Controller
{
    public function __invoke(Order $order, Request $request, CompanySettingsService $settings): Response
    {
        abort_unless($request->user()?->canPerformModelAbility('view', Order::class), 403);

        $order->load(['company', 'customer', 'items.product', 'reseller']);
        $invoice = $settings->invoice($order->company);
        $productImages = ! empty($invoice['show_images'])
            ? $order->items->mapWithKeys(fn ($item): array => [
                $item->getKey() => $settings->invoiceImagePath($item->product?->image, $order->company),
            ])->all()
            : [];

        return Pdf::loadView('orders.pdf', [
            'order' => $order,
            'company' => $settings->profile($order->company),
            'invoice' => $invoice,
            'productImages' => $productImages,
            // The reseller's own logo heads their customer's invoice.
            'resellerLogoPath' => $order->showsResellerBranding()
                ? $settings->invoiceImagePath($order->reseller?->reseller_logo ?: $order->reseller?->reseller_logo_dark, $order->company)
                : null,
        ])->setPaper('a4')->download($order->order_number.'.pdf');
    }
}
