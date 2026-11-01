<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderSummaryCardPdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The order summary card as a PDF (owner request 2026-11-01), opened from the order page. The card is drawn by
 * OrderSummaryCardImageService, so a server without Pango answers with a plain message instead of a PDF with broken
 * Bangla.
 */
class OrderSummaryCardPdfController extends Controller
{
    public function __invoke(Order $order, Request $request, OrderSummaryCardPdfService $pdf): Response
    {
        abort_unless($request->user()?->canPerformModelAbility('view', Order::class), 403);

        $bytes = $pdf->render($order);

        if ($bytes === null) {
            return response(__('This server cannot draw the order summary card yet: :problem', ['problem' => (string) $pdf->problem()]), 503)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="order-'.str($order->order_number)->slug().'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
