<?php

namespace App\Jobs;

use App\Services\OrderStatusSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOrderPlacedSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** An SMS is not safe to repeat, so a failed one is not retried. */
    public int $tries = 1;

    public function __construct(public int $orderId) {}

    public function handle(OrderStatusSmsService $sms): void
    {
        $sms->sendPlaced($this->orderId);
    }
}
