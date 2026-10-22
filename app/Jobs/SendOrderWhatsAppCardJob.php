<?php

namespace App\Jobs;

use App\Services\OrderWhatsAppCardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOrderWhatsAppCardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A message to a customer is not safe to repeat, so a failed one is not retried. */
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $orderId, public string $event, public ?int $transitionId = null) {}

    public function handle(OrderWhatsAppCardService $cards): void
    {
        $cards->send($this->orderId, $this->event, $this->transitionId);
    }
}
