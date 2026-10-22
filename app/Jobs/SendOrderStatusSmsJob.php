<?php

namespace App\Jobs;

use App\Models\OrderStatusTransition;
use App\Services\OrderStatusSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOrderStatusSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** An SMS is not safe to repeat, so a failed one is not retried. */
    public int $tries = 1;

    public function __construct(public int $transitionId) {}

    public function handle(OrderStatusSmsService $sms): void
    {
        $transition = OrderStatusTransition::withoutGlobalScopes()->find($this->transitionId);

        if ($transition) {
            $sms->send($transition);
        }
    }
}
