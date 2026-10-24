<?php

namespace App\Services;

use App\Jobs\SendOrderEmailJob;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\StorefrontSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Mails the customer when their order is placed and whenever it reaches a new
 * stage, with the wording the owner wrote on Settings → Email Templates (owner
 * request 2026-10-24); the email twin of OrderStatusSmsService, with the same
 * stages. A stage with no wording sends nothing, so email only starts once the
 * owner has written one.
 *
 * Not mailed for: an import of past orders, a reseller's store order, a Delivery
 * Challan sale (status mails only), a customer without an email address or who
 * asked not to be contacted. The send goes through the email account of the
 * order's own site (Integrations → Email Setup), else the company's primary site,
 * and every result lands in the Email Log. The same service sends one of the owner's
 * own templates by hand from an order (sendCustom).
 */
class OrderStatusEmailService
{
    public function __construct(
        protected EmailTemplateService $templates,
        protected StorefrontNotificationService $notifications,
        protected OrderStatusSmsService $stages,
        protected EmailLogService $logs,
    ) {}

    /** Queues the "order placed" email for a new order, but only when the company has written that wording. */
    public function dispatchForOrderPlaced(Order $order): void
    {
        if ($order->isHistoricalImport() || filled($order->reseller_customer_id)) {
            return;
        }

        if (! $this->templates->written((int) $order->company_id, EmailTemplateService::ORDER_PLACED)) {
            return;
        }

        // Waits a little, like the SMS: the items and the first payment are saved just after the order itself.
        SendOrderEmailJob::dispatch((int) $order->getKey(), 'placed')
            ->delay(now()->addSeconds(OrderStatusSmsService::PLACED_DELAY_SECONDS))
            ->afterCommit();
    }

    /** Queues the email for a status change, but only when that stage has wording. */
    public function dispatchForTransition(OrderStatusTransition $transition): void
    {
        $stage = $this->stages->stageFor($transition);

        if ($stage === null || ! $this->templates->written((int) $transition->company_id, EmailTemplateService::orderKey($stage))) {
            return;
        }

        SendOrderEmailJob::dispatch((int) $transition->order_id, $stage, (int) $transition->getKey())->afterCommit();
    }

    /**
     * Sends the "placed" email ($event "placed") or the email of a stage. Returns whether it was mailed.
     * A reason it was not (no address, opted out ...) is written to the Email Log; having no wording is not.
     */
    public function send(int $orderId, string $event, ?int $transitionId = null): bool
    {
        $order = Order::withoutGlobalScopes()->with(['customer', 'company', 'storefrontSetting', 'latestCourierBooking', 'items.product', 'items.productVariant'])->find($orderId);

        if ($order === null) {
            return false;
        }

        $companyId = (int) $order->company_id;
        $placed = $event === 'placed';
        $row = $this->templates->row($companyId, $placed ? EmailTemplateService::ORDER_PLACED : EmailTemplateService::orderKey($event));

        if ($row === null) {
            return false;
        }

        $email = trim((string) $order->customer?->email);
        $recipient = $email !== '' ? $email : '-';

        // A Delivery Challan sale was handed over in the shop: the status mails (shipped, delivered ...) do not fit it.
        if ($order->isHistoricalImport() || filled($order->reseller_customer_id) || (! $placed && $order->source === Order::SOURCE_TRADE_CHALLAN)) {
            return $this->skip($companyId, $recipient, EmailLogService::REASON_EXCLUDED);
        }

        if ($email === '') {
            return $this->skip($companyId, $recipient, EmailLogService::REASON_NO_EMAIL);
        }

        if ($order->customer?->opted_out_at !== null) {
            return $this->skip($companyId, $recipient, EmailLogService::REASON_OPTED_OUT);
        }

        $setting = $this->settingFor($order);

        if ($setting === null || ! $this->notifications->emailDeliveryAvailable($setting)) {
            return $this->skip($companyId, $recipient, EmailLogService::REASON_NOT_CONFIGURED);
        }

        // A retried job must never mail the same change twice, and the same stage twice in a row is not news.
        $once = $transitionId !== null ? 'order-status-email:'.$transitionId : 'order-placed-email:'.$orderId;

        if (! Cache::add($once, true, now()->addDays(2)) || (! $placed && ! Cache::add("order-status-email:{$orderId}:{$event}", true, now()->addMinutes(5)))) {
            return $this->skip($companyId, $recipient, EmailLogService::REASON_DUPLICATE);
        }

        $status = $placed ? (Order::STATUSES[$order->status] ?? (string) str((string) $order->status)->headline()) : Order::WORKFLOW_STAGES[$event];
        $message = $this->templates->render($companyId, $row->key, $this->values($order, __($status)), $row);

        return $message !== null
            && $this->notifications->sendMailable($setting, $email, $this->templates->wrap($companyId, $message), EmailLog::KIND_ORDER);
    }

    /**
     * Sends one of the owner's own templates to the order's customer (the "Email the customer" button).
     * Returns null when the server accepted it, else a sentence saying why not.
     */
    public function sendCustom(Order $order, EmailTemplate $template): ?string
    {
        $companyId = (int) $order->company_id;
        $order->loadMissing(['customer', 'company', 'storefrontSetting', 'latestCourierBooking', 'items.product', 'items.productVariant']);
        $email = trim((string) $order->customer?->email);

        if ($email === '') {
            return __('This customer has no email address.');
        }

        if ($order->customer?->opted_out_at !== null) {
            return __('This customer asked not to be contacted.');
        }

        $setting = $this->settingFor($order);

        if ($setting === null || ! $this->notifications->emailDeliveryAvailable($setting)) {
            return __('Email sending is not set up for this company. Set it up in Settings → Integrations → Email Setup.');
        }

        $status = Order::WORKFLOW_STAGES[$order->workflowStage()] ?? (Order::STATUSES[$order->status] ?? (string) str((string) $order->status)->headline());
        $message = $this->templates->render($companyId, (string) $template->key, $this->values($order, __($status)), $template);

        if ($message === null) {
            return __('This template has no text yet.');
        }

        return $this->notifications->sendMailable($setting, $email, $this->templates->wrap($companyId, $message), EmailLog::KIND_TEMPLATE)
            ? null
            : __('The email could not be sent. The reason is in Settings → Logs → Email.');
    }

    /** The site whose email account sends: the order's own site when it can send, else the company's primary or another site. */
    public function settingFor(Order $order): ?StorefrontSetting
    {
        $companyId = (int) $order->company_id;
        $candidates = array_values(array_filter([$order->siteSetting(), StorefrontSetting::primaryFor($companyId), StorefrontSetting::bladeFor($companyId), StorefrontSetting::nextjsFor($companyId)]));

        foreach ($candidates as $setting) {
            if ($this->notifications->mailConfigured($setting)) {
                return $setting;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * What the owner's wording can use. `items` is "Product x2, Other x1", in full.
     *
     * @return array<string, string>
     */
    public function values(Order $order, string $status): array
    {
        $order->loadMissing(['items.product', 'items.productVariant']);
        $items = $order->items
            ->map(fn ($item): string => trim(($item->product?->name ?? __('Item')).' x'.(int) $item->quantity))
            ->implode(', ');

        return [
            'name' => (string) ($order->customer?->name ?? $order->customer_name),
            'order' => (string) ($order->order_number ?: $order->getKey()),
            'status' => $status,
            'items' => $items,
            'total' => $this->money($order->total_amount),
            'due' => $this->money($order->due_amount),
            'tracking' => (string) $order->latestCourierBooking?->tracking_id,
            'company' => (string) $order->company?->name,
        ];
    }

    protected function money(mixed $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
    }

    protected function skip(int $companyId, string $recipient, string $reason): bool
    {
        $this->logs->skipped($companyId, EmailLog::KIND_ORDER, $recipient, $reason);

        return false;
    }
}
