<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Order;
use App\Services\CompanyContext;
use App\Services\OrderStatusEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Mails a customer about their order: "placed" for a new order, else the
 * stage name (OrderStatusEmailService). Runs inside its company's context and
 * restores the previous one, as every queued job must.
 */
class SendOrderEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** An email is not safe to repeat, so a failed one is not retried. */
    public int $tries = 1;

    public function __construct(public int $orderId, public string $event, public ?int $transitionId = null) {}

    public function handle(OrderStatusEmailService $emails, CompanyContext $context): void
    {
        $companyId = Order::withoutGlobalScopes()->whereKey($this->orderId)->value('company_id');
        $company = $companyId ? Company::query()->find($companyId) : null;

        if ($company === null) {
            return;
        }

        // within() puts the previous company back, so a job that runs inside a request (the sync queue)
        // never leaves that request without its own company.
        $context->within($company, fn (): bool => $emails->send($this->orderId, $this->event, $this->transitionId));
    }
}
