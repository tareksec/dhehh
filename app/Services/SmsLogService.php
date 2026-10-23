<?php

namespace App\Services;

use App\Models\SmsLog;
use App\Models\StorefrontSetting;
use Throwable;

/**
 * Writes the SMS log (owner request 2026-10-23): one row per order SMS, from
 * "waiting in the queue" to "sent", "refused by the gateway" or "skipped" with
 * the reason, so the owner can see why a customer got no text instead of
 * guessing that the gateway is broken. Logging never stops an SMS: a failed
 * write is reported and ignored. Every other SMS (login and recovery codes,
 * reminders, broadcasts) is counted too, for the SMS dashboard, but only as a
 * phone number, a result and a part count: its text, which may be a code, is
 * never stored.
 */
class SmsLogService
{
    /** A message waiting longer than this means no queue worker is picking it up. */
    public const STUCK_AFTER_MINUTES = 5;

    /** @return array<string, string> reason key => what it means for the owner */
    public static function reasonLabels(): array
    {
        return [
            'no_wording' => __('No wording is written for this message on the SMS Templates page.'),
            'no_phone' => __('The customer has no phone number.'),
            'opted_out' => __('The number is on the opt-out list.'),
            'no_gateway' => __('No SMS gateway is set. Fill in Integrations → SMS Gateway.'),
            'bad_gateway_url' => __('The SMS gateway URL of the site named in the brackets has no {phone} or no {message} in it, so nothing was sent. Open Integrations → SMS Gateway, choose that site in the Site picker and replace the sample words (Receiver, TestSMS) with {phone} and {message}.'),
            'no_api_key' => __('The SMS API key is empty. Enter it in Integrations → SMS Gateway → SMS API key and save.'),
            'duplicate' => __('The same message was already sent, so it was not sent twice.'),
            'excluded' => __('Not sent for imported past orders or reseller store orders.'),
            'gateway_refused' => __('The SMS gateway refused the message.'),
            'gateway_unreachable' => __('The SMS gateway could not be reached or sent an error.'),
        ];
    }

    /** Puts a message in the log as waiting for the queue worker. */
    public function queued(int $companyId, string $kind, ?int $orderId, ?string $reference = null, ?string $phone = null): ?SmsLog
    {
        return $this->safely(fn (): SmsLog => SmsLog::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'kind' => $kind,
            'order_id' => $orderId,
            'reference' => $reference,
            'phone' => $phone,
            'status' => SmsLog::STATUS_QUEUED,
        ]));
    }

    /** The waiting row of this message (the job picks it up), or a new one when the message was sent without the queue. */
    public function claim(int $companyId, string $kind, ?int $orderId, ?string $reference = null): ?SmsLog
    {
        return $this->safely(function () use ($companyId, $kind, $orderId, $reference): SmsLog {
            $row = SmsLog::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('kind', $kind)
                ->where('order_id', $orderId)
                ->where('reference', $reference)
                ->where('status', SmsLog::STATUS_QUEUED)
                ->latest('id')
                ->first();

            return $row ?? SmsLog::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'kind' => $kind,
                'order_id' => $orderId,
                'reference' => $reference,
                'status' => SmsLog::STATUS_QUEUED,
            ]);
        });
    }

    /**
     * Closes a row. $extra may hold phone, message, segments, gateway_code and detail.
     *
     * @param  array{phone?: ?string, message?: ?string, segments?: ?int, gateway_code?: ?int, detail?: ?string}  $extra
     */
    public function finish(?SmsLog $log, string $status, ?string $reason = null, array $extra = []): void
    {
        if ($log === null) {
            return;
        }

        $this->safely(function () use ($log, $status, $reason, $extra): void {
            $log->forceFill(array_filter([
                'phone' => $extra['phone'] ?? null,
                'message' => $extra['message'] ?? null,
                'segments' => $extra['segments'] ?? null,
                'gateway_code' => $extra['gateway_code'] ?? null,
                'detail' => isset($extra['detail']) ? mb_substr((string) $extra['detail'], 0, 255) : null,
            ], fn ($value): bool => $value !== null) + ['status' => $status, 'reason' => $reason])->save();
        });
    }

    /** Logs a message that was skipped before it was ever queued. */
    public function skipped(int $companyId, string $kind, ?int $orderId, ?string $reference, string $reason, ?string $phone = null, ?string $detail = null): void
    {
        $this->finish(
            $this->queued($companyId, $kind, $orderId, $reference, $phone),
            SmsLog::STATUS_SKIPPED,
            $reason,
            ['phone' => $phone, 'detail' => $detail],
        );
    }

    /**
     * Counts an SMS sent without its own log row (a login or recovery code, a reminder, a broadcast message): the
     * phone, what the gateway answered and how many parts it used, never the text. A site with no gateway at all
     * sent nothing, so it is not counted.
     *
     * @param  array{ok: bool, reason: ?string, code: ?int, meaning: ?string, detail: ?string, segments: int}  $result
     */
    public function other(StorefrontSetting $setting, string $phone, array $result): void
    {
        if ($result['reason'] === 'no_gateway') {
            return;
        }

        $this->finish(
            $this->queued((int) $setting->company_id, SmsLog::KIND_OTHER, null, null, $phone),
            $result['ok'] ? SmsLog::STATUS_SENT : SmsLog::STATUS_FAILED,
            $result['reason'],
            ['segments' => $result['segments'], 'gateway_code' => $result['code'], 'detail' => $result['meaning'] ?? $result['detail']],
        );
    }

    /** How many messages have waited so long that no queue worker can be running. */
    public function stuckCount(?int $companyId = null): int
    {
        return (int) ($this->safely(fn (): int => SmsLog::withoutGlobalScopes()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->where('status', SmsLog::STATUS_QUEUED)
            ->where('created_at', '<=', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->count()) ?? 0);
    }

    protected function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
