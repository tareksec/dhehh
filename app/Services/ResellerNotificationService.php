<?php

namespace App\Services;

use App\Jobs\SendResellerNotificationJob;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ResellerCommission;
use App\Models\ResellerPayoutRequest;
use App\Models\StorefrontSetting;
use App\Services\Crm\ContactSuppressionService;
use App\Support\MoneyFormatter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SMS to a reseller at three moments (owner request 2026-10-16): their
 * application is approved, a profit becomes payable (the order was delivered),
 * and a withdrawal is paid. The wording is the owner's: each message is written
 * in Reseller Settings and starts empty, and while a message is empty nothing
 * is sent for that moment. Sending goes through the company's SMS gateway
 * (Settings → Integrations) and runs on the queue, so a slow gateway never
 * holds up an order, a courier webhook or a payment. Numbers on the opt-out
 * list are skipped, a message is never sent twice for the same profit or
 * withdrawal, and one failed send never stops anything else.
 */
class ResellerNotificationService
{
    public const EVENT_APPROVED = 'approved';

    public const EVENT_PROFIT_PAYABLE = 'profit_payable';

    public const EVENT_PAYOUT_PAID = 'payout_paid';

    /** The storefront_settings column holding the wording of each message. */
    public const COLUMNS = [
        self::EVENT_APPROVED => 'reseller_message_approved',
        self::EVENT_PROFIT_PAYABLE => 'reseller_message_profit_payable',
        self::EVENT_PAYOUT_PAID => 'reseller_message_payout_paid',
    ];

    public function __construct(
        protected StorefrontNotificationService $notifications,
        protected ContactSuppressionService $suppression,
        protected ResellerCommissionService $commissions,
    ) {}

    /** @return array<string, list<string>> the words a message may contain, per moment */
    public static function placeholders(): array
    {
        return [
            self::EVENT_APPROVED => [':name', ':store_link', ':portal'],
            self::EVENT_PROFIT_PAYABLE => [':name', ':order', ':profit', ':balance'],
            self::EVENT_PAYOUT_PAID => [':name', ':amount', ':reference', ':balance'],
        ];
    }

    /** Wording a company can load as a starting point; it is only a draft until the owner saves it. */
    public static function suggestedWording(string $event): string
    {
        return match ($event) {
            self::EVENT_APPROVED => __('Hello :name, your reseller application is approved. Log in here: :portal Your store: :store_link'),
            self::EVENT_PROFIT_PAYABLE => __('Hello :name, order :order is delivered and your profit :profit is now payable. You can withdraw up to :balance.'),
            self::EVENT_PAYOUT_PAID => __('Hello :name, :amount was paid to you. Reference: :reference.'),
            default => '',
        };
    }

    /** The company's wording for a moment; an empty string means that message is switched off. */
    public function wording(?int $companyId, string $event): string
    {
        $column = self::COLUMNS[$event] ?? null;

        if ($column === null || $companyId === null) {
            return '';
        }

        return trim((string) StorefrontSetting::bladeFor($companyId)?->{$column});
    }

    /**
     * Queues the SMS when the company has written wording for this moment.
     * Never throws: a messaging problem must not undo the save that caused it.
     *
     * @param  string|null  $once  when given, the same key is never sent twice
     */
    public function notify(string $event, Customer $reseller, ?int $subjectId = null, ?string $once = null): void
    {
        try {
            if ($this->wording((int) $reseller->company_id, $event) === '') {
                return;
            }

            SendResellerNotificationJob::dispatch((int) $reseller->company_id, $event, (int) $reseller->getKey(), $subjectId, $once)
                ->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Sends one message now (the queued job calls this with the company already set). Returns whether an SMS left. */
    public function send(int $companyId, string $event, int $resellerId, ?int $subjectId, ?string $once): bool
    {
        $company = Company::query()->find($companyId);
        $reseller = Customer::query()->find($resellerId);
        $wording = $this->wording($companyId, $event);
        $phone = trim((string) $reseller?->phone);

        if (! $company || ! $reseller || $wording === '' || $phone === '') {
            return false;
        }

        $setting = StorefrontSetting::primaryFor($companyId);

        if ($setting === null || ! $this->notifications->smsConfigured($setting) || $this->suppression->suppressed($companyId, $phone)) {
            return false;
        }

        $variables = $this->variables($event, $company, $reseller, $subjectId);

        if ($variables === null) {
            return false;
        }

        $cacheKey = $once !== null ? "reseller-sms:{$event}:{$once}" : null;

        if ($cacheKey !== null && ! Cache::add($cacheKey, true, now()->addDays(30))) {
            return false;
        }

        $sent = $this->notifications->sendSms($setting, $phone, strtr($wording, $variables));

        if (! $sent) {
            // Not sent, so a later try for the same profit or withdrawal may still go out.
            if ($cacheKey !== null) {
                Cache::forget($cacheKey);
            }

            Log::warning('Reseller SMS was not sent', ['company_id' => $companyId, 'event' => $event, 'reseller_id' => $resellerId]);
        }

        return $sent;
    }

    /**
     * The values for the words in a message, or null when what the message is
     * about is gone or no longer qualifies (a profit that was cancelled again,
     * a withdrawal that is no longer paid).
     *
     * @return array<string, string>|null
     */
    protected function variables(string $event, Company $company, Customer $reseller, ?int $subjectId): ?array
    {
        $currency = $company->currency ?: 'BDT';
        $name = (string) ($reseller->business_name ?: $reseller->name);
        $balance = MoneyFormatter::currency($this->commissions->balances($reseller)['available'], $currency);

        switch ($event) {
            case self::EVENT_APPROVED:
                $host = $company->resellerPortalHost();

                return [
                    ':name' => $name,
                    ':store_link' => (string) $reseller->resellerStoreUrl(),
                    ':portal' => $host ? 'https://'.$host.'/portal' : '',
                ];

            case self::EVENT_PROFIT_PAYABLE:
                $commission = ResellerCommission::query()->with('order')->find($subjectId);

                if (! $commission || $commission->status !== ResellerCommission::STATUS_PAYABLE || (float) $commission->amount <= 0) {
                    return null;
                }

                return [
                    ':name' => $name,
                    ':order' => (string) $commission->order?->order_number,
                    ':profit' => MoneyFormatter::currency($commission->amount, $currency),
                    ':balance' => $balance,
                ];

            case self::EVENT_PAYOUT_PAID:
                $payout = ResellerPayoutRequest::query()->find($subjectId);

                if (! $payout || $payout->status !== ResellerPayoutRequest::STATUS_PAID) {
                    return null;
                }

                return [
                    ':name' => $name,
                    ':amount' => MoneyFormatter::currency($payout->amount, $currency),
                    ':reference' => (string) $payout->payment_reference,
                    ':balance' => $balance,
                ];
        }

        return null;
    }
}
