<?php

namespace App\Filament\Pages\Concerns;

use App\Services\Email\Inbox\EmailThreadActions;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;

/**
 * Snooze in the Email page (Phase 2, docs/email-inbox-plan.md): hide conversations from the Inbox until a time, then they
 * come back unread (`email:wake-snoozed`). The ready-made times and the typed time are worked out in the company's time
 * zone. Used by App\Filament\Pages\EmailInbox, which provides company(), user(), ownIds(), authorizeManage() and forgetData().
 */
trait SnoozesEmail
{
    /** The date and time typed in the "Pick date and time" box, as the browser sends it (Y-m-d\TH:i). */
    public string $snoozeCustom = '';

    /**
     * The ready-made choices and the moment each means right now.
     *
     * @return array<string, array{0: string, 1: CarbonInterface}>
     */
    public function snoozeChoices(): array
    {
        $now = now($this->snoozeZone());

        return [
            'later' => [__('Later today'), $now->copy()->addHours(3)->startOfMinute()],
            'tomorrow' => [__('Tomorrow morning'), $now->copy()->addDay()->setTime(8, 0)],
            'three_days' => [__('In 3 days'), $now->copy()->addDays(3)->setTime(8, 0)],
            'next_week' => [__('Next week'), $now->copy()->addWeek()->setTime(8, 0)],
        ];
    }

    /** The earliest time the "Pick date and time" box offers, in the form the browser wants. */
    public function snoozeMin(): string
    {
        return now($this->snoozeZone())->addMinutes(5)->format('Y-m-d\TH:i');
    }

    public function snoozeSelected(string $when): void
    {
        $this->snooze($when, $this->selected);
    }

    public function snoozeThread(string $when): void
    {
        if ($this->threadId !== null) {
            $this->snooze($when, [$this->threadId]);
        }
    }

    /** @param  array<int, int|string>  $ids */
    public function snooze(string $when, array $ids): void
    {
        $this->authorizeManage();
        $until = $this->snoozeTime($when);

        if ($until === null) {
            Notification::make()->title(__('Pick a time that is still ahead (within a year).'))->warning()->send();

            return;
        }

        $ids = $this->ownIds($ids);
        $snoozed = $ids === [] ? 0 : app(EmailThreadActions::class)->snooze($ids, $until, $this->user());

        if ($snoozed === 0) {
            Notification::make()->title(__('Only conversations in the Inbox can be snoozed.'))->warning()->send();

            return;
        }

        $this->undo = [
            'message' => __(':count snoozed until :time', ['count' => $snoozed, 'time' => $until->translatedFormat('D, M j, g:i A')]),
            'moves' => [],
            'snoozed' => $ids,
        ];

        if ($this->threadId !== null && in_array($this->threadId, $ids, true)) {
            $this->threadId = null;
        }

        $this->snoozeCustom = '';
        $this->selected = [];
        $this->forgetData();
    }

    /** One of the ready-made keys, or "custom" for the typed time. Null when it is not a time ahead (at most a year). */
    protected function snoozeTime(string $when): ?CarbonInterface
    {
        $until = null;

        if ($when === 'custom') {
            try {
                $until = Carbon::createFromFormat('!Y-m-d\TH:i', trim($this->snoozeCustom), $this->snoozeZone());
            } catch (\Throwable) {
                $until = null;
            }
        } else {
            $until = $this->snoozeChoices()[$when][1] ?? null;
        }

        if (! $until instanceof CarbonInterface || $until->lte(now()) || $until->gt(now()->addYear())) {
            return null;
        }

        return CarbonImmutable::instance($until);
    }

    protected function snoozeZone(): string
    {
        return $this->company()?->timezone ?: config('app.timezone');
    }
}
