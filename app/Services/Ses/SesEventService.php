<?php

namespace App\Services\Ses;

use App\Models\EmailLog;
use App\Models\StorefrontSetting;
use App\Services\EmailLogService;

/**
 * Applies what Amazon SES reports about a message (delivered, bounced, the
 * recipient complained) to the Email Log row of that message (owner request
 * 2026-10-23). A row is found by the SES message ID it was saved with, else by
 * recipient among the recent unanswered sends; an event for a message the log
 * never saw becomes a row of its own. A worse result is never replaced by a
 * better one (a bounce stays a bounce).
 */
class SesEventService
{
    /** How far back an event may reach for a row that has no message ID. */
    private const LOOKBACK_DAYS = 7;

    /** Higher wins: the row only moves to a status that ranks at least as high as its own. */
    private const RANK = [
        EmailLog::STATUS_SKIPPED => 0,
        EmailLog::STATUS_FAILED => 0,
        EmailLog::STATUS_SENT => 1,
        EmailLog::STATUS_DELIVERED => 2,
        EmailLog::STATUS_BOUNCED => 3,
        EmailLog::STATUS_COMPLAINED => 4,
    ];

    /**
     * @param  array<string, mixed>  $event  the SES event (the "Message" an SNS notification carries, decoded)
     * @return int how many log rows were touched
     */
    public function handle(StorefrontSetting $setting, array $event): int
    {
        $mail = (array) ($event['mail'] ?? []);
        $messageId = (string) ($mail['messageId'] ?? '');
        $type = strtolower((string) ($event['eventType'] ?? $event['notificationType'] ?? ''));
        $touched = 0;

        foreach ($this->outcomes($type, $event, $mail) as $outcome) {
            $this->apply((int) $setting->company_id, $messageId, $outcome);
            $touched++;
        }

        return $touched;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $mail
     * @return array<int, array{recipient: string, status: string, reason: ?string, detail: ?string}>
     */
    protected function outcomes(string $type, array $event, array $mail): array
    {
        $rows = [];

        if ($type === 'delivery') {
            foreach ((array) data_get($event, 'delivery.recipients', $mail['destination'] ?? []) as $recipient) {
                $rows[] = ['recipient' => (string) $recipient, 'status' => EmailLog::STATUS_DELIVERED, 'reason' => null, 'detail' => null];
            }
        } elseif ($type === 'bounce') {
            $bounceType = (string) data_get($event, 'bounce.bounceType', '');
            $subType = (string) data_get($event, 'bounce.bounceSubType', '');
            $reason = match (true) {
                $subType === 'Suppressed' => EmailLogService::REASON_BOUNCE_SUPPRESSED,
                $bounceType === 'Permanent' => EmailLogService::REASON_BOUNCE_PERMANENT,
                $bounceType === 'Transient' => EmailLogService::REASON_BOUNCE_TRANSIENT,
                default => EmailLogService::REASON_BOUNCE_UNDETERMINED,
            };

            foreach ((array) data_get($event, 'bounce.bouncedRecipients', []) as $recipient) {
                $rows[] = [
                    'recipient' => (string) ($recipient['emailAddress'] ?? ''),
                    'status' => EmailLog::STATUS_BOUNCED,
                    'reason' => $reason,
                    'detail' => $this->detail([$subType, $recipient['diagnosticCode'] ?? $recipient['status'] ?? null]),
                ];
            }
        } elseif ($type === 'complaint') {
            foreach ((array) data_get($event, 'complaint.complainedRecipients', []) as $recipient) {
                $rows[] = [
                    'recipient' => (string) ($recipient['emailAddress'] ?? ''),
                    'status' => EmailLog::STATUS_COMPLAINED,
                    'reason' => EmailLogService::REASON_COMPLAINT,
                    'detail' => $this->detail([data_get($event, 'complaint.complaintFeedbackType')]),
                ];
            }
        } elseif ($type === 'reject') {
            foreach ((array) ($mail['destination'] ?? []) as $recipient) {
                $rows[] = [
                    'recipient' => (string) $recipient,
                    'status' => EmailLog::STATUS_FAILED,
                    'reason' => EmailLogService::REASON_SES_REJECTED,
                    'detail' => $this->detail([data_get($event, 'reject.reason')]),
                ];
            }
        }

        return array_values(array_filter($rows, fn (array $row): bool => $row['recipient'] !== ''));
    }

    /**
     * @param  array{recipient: string, status: string, reason: ?string, detail: ?string}  $outcome
     */
    protected function apply(int $companyId, string $messageId, array $outcome): void
    {
        $recipient = mb_strtolower($outcome['recipient']);
        $log = $this->findRow($companyId, $messageId, $recipient);

        if ($log === null) {
            EmailLog::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'kind' => EmailLog::KIND_OTHER,
                'recipient' => mb_substr($outcome['recipient'], 0, 254),
                'via' => 'ses:event',
                'message_id' => $messageId === '' ? null : mb_substr($messageId, 0, 100),
                'status' => $outcome['status'],
                'reason' => $outcome['reason'],
                'detail' => $outcome['detail'],
            ]);

            return;
        }

        if ((self::RANK[$outcome['status']] ?? 0) < (self::RANK[$log->status] ?? 0)) {
            return;
        }

        $log->forceFill([
            'status' => $outcome['status'],
            'reason' => $outcome['reason'],
            'detail' => $outcome['detail'],
            'message_id' => $log->message_id ?: ($messageId === '' ? null : mb_substr($messageId, 0, 100)),
        ])->save();
    }

    protected function findRow(int $companyId, string $messageId, string $recipient): ?EmailLog
    {
        $rows = fn () => EmailLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereRaw('lower(recipient) = ?', [$recipient]);

        if ($messageId !== '') {
            $byId = $rows()->where('message_id', $messageId)->orderByDesc('id')->first();

            if ($byId !== null) {
                return $byId;
            }
        }

        return $rows()
            ->whereNull('message_id')
            ->whereIn('status', [EmailLog::STATUS_SENT, EmailLog::STATUS_DELIVERED])
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->orderByDesc('id')
            ->first();
    }

    /** @param  array<int, mixed>  $parts */
    protected function detail(array $parts): ?string
    {
        $text = collect($parts)->filter(fn ($part) => is_scalar($part) && trim((string) $part) !== '')->map(fn ($part) => trim((string) $part))->implode(' - ');

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
