<?php

namespace App\Filament\Pages\Concerns;

use App\Models\EmailMessage;
use App\Services\Email\Inbox\EmailSendException;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;

/**
 * Send later in the Email page (Phase 2, docs/email-inbox-plan.md): a message written in the compose window can be set to
 * go at a chosen time (`email:send-due` sends it, whether or not anyone has the page open). Until then it is shown in the
 * conversation and in the Scheduled view, to every member of staff; only its author can take it back or send it now.
 * The ready-made and typed times are worked out in the company's time zone. Used by App\Filament\Pages\EmailInbox, which
 * provides company(), user(), authorizeManage(), outbound(), saveCompose(), composeDraft(), resetCompose() and forgetData().
 */
trait SchedulesEmail
{
    /** The date and time typed in the "Pick date and time" box of Send later, as the browser sends it (Y-m-d\TH:i). */
    public string $scheduleCustom = '';

    /**
     * The ready-made choices and the moment each means right now.
     *
     * @return array<string, array{0: string, 1: CarbonInterface}>
     */
    public function scheduleChoices(): array
    {
        $now = now($this->scheduleZone());

        return [
            'hour' => [__('In 1 hour'), $now->copy()->addHour()->startOfMinute()],
            'tomorrow' => [__('Tomorrow morning'), $now->copy()->addDay()->setTime(8, 0)],
            'three_days' => [__('In 3 days'), $now->copy()->addDays(3)->setTime(8, 0)],
            'next_week' => [__('Next week'), $now->copy()->addWeek()->setTime(8, 0)],
        ];
    }

    /** The earliest time the "Pick date and time" box offers, in the form the browser wants. */
    public function scheduleMin(): string
    {
        return now($this->scheduleZone())->addMinutes(2)->format('Y-m-d\TH:i');
    }

    /** Sends the open compose window at one of the ready-made times, or at the typed one ("custom"). */
    public function sendLater(string $when): void
    {
        $this->authorizeManage();
        $at = $this->scheduleTime($when);

        if ($at === null) {
            Notification::make()->title(__('Pick a time that is still ahead (within a year).'))->warning()->send();

            return;
        }

        $this->saveCompose();
        $draft = $this->composeDraft();

        if ($draft === null) {
            return;
        }

        if ($this->composeInvalid !== []) {
            Notification::make()->title(__('Check these addresses: :list', ['list' => implode(', ', $this->composeInvalid)]))->danger()->send();

            return;
        }

        try {
            $message = $this->outbound()->queue($draft, $this->user(), $at);
        } catch (EmailSendException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__('Scheduled for :time', ['time' => $at->translatedFormat('D, M j, g:i A')]))->success()->send();

        $this->scheduleCustom = '';
        $this->resetCompose();
        $this->threadId ??= $message->thread_id;
        $this->forgetData();
    }

    /** Takes a scheduled message back into the compose window (its author only). */
    public function cancelScheduled(int $messageId): void
    {
        $this->authorizeManage();
        $message = $this->scheduledOfOpenThread($messageId);

        if ($message === null) {
            return;
        }

        if ($this->outbound()->cancelScheduled($message)) {
            $this->loadCompose($message->fresh());
            Notification::make()->title(__('Scheduling cancelled. Your message is open again.'))->success()->send();
        } else {
            Notification::make()->title(__('Too late to take it back. The message has been sent.'))->warning()->send();
        }

        $this->forgetData();
    }

    /** Sends a scheduled message now (its author only). */
    public function sendScheduledNow(int $messageId): void
    {
        $this->authorizeManage();
        $message = $this->scheduledOfOpenThread($messageId);

        if ($message === null) {
            return;
        }

        if ($this->outbound()->sendScheduledNow($message)) {
            Notification::make()->title(__('Message sent'))->success()->send();
        } else {
            Notification::make()->title(__('The message was not sent: :reason', ['reason' => (string) $message->fresh()?->error]))->danger()->send();
        }

        $this->forgetData();
    }

    /** One of the signed-in person's own scheduled messages in the open conversation. */
    protected function scheduledOfOpenThread(int $messageId): ?EmailMessage
    {
        $thread = $this->thread;

        return $thread === null ? null : EmailMessage::query()
            ->where('thread_id', $thread->getKey())
            ->where('status', EmailMessage::STATUS_SCHEDULED)
            ->where('user_id', $this->user()->getKey())
            ->find($messageId);
    }

    /** One of the ready-made keys, or "custom" for the typed time. Null when it is not a time ahead (at most a year). */
    protected function scheduleTime(string $when): ?CarbonInterface
    {
        $at = null;

        if ($when === 'custom') {
            try {
                $at = Carbon::createFromFormat('!Y-m-d\TH:i', trim($this->scheduleCustom), $this->scheduleZone());
            } catch (\Throwable) {
                $at = null;
            }
        } else {
            $at = $this->scheduleChoices()[$when][1] ?? null;
        }

        if (! $at instanceof CarbonInterface || $at->lt(now()->addMinute()) || $at->gt(now()->addYear())) {
            return null;
        }

        return CarbonImmutable::instance($at);
    }

    protected function scheduleZone(): string
    {
        return $this->company()?->timezone ?: config('app.timezone');
    }
}
