<?php

namespace App\Console\Commands\Ims;

use App\Ims\Capital\MaturityQueue;
use App\Ims\Notifications\ImsNotifier;
use App\Ims\Settings\ImsSettings;
use App\Models\Company;

/**
 * Daily: "your investment matures soon" once, the set number of days before
 * the maturity date (PRD FR-53, 7 days unless changed in IMS settings).
 */
class SendMaturityReminders extends ImsCompanyCommand
{
    protected $signature = 'ims:maturity-reminders {--company= : Company id (default: every company with IMS on)}';

    protected $description = 'IMS v2: remind investors before their investment matures (once).';

    protected function job(): string
    {
        return 'maturity_reminders';
    }

    protected function runFor(Company $company): int
    {
        $days = (int) ImsSettings::get($company, 'maturity_reminder_days', 7);
        $sent = 0;

        foreach (app(MaturityQueue::class)->dueWithin($days) as $row) {
            $record = $row['record'];

            if ($record->maturity_reminder_sent_at !== null || $row['overdue'] || $record->investor === null) {
                continue;
            }

            app(ImsNotifier::class)->send('maturity_near', $record->investor, ['amount' => $row['outstanding'], 'date' => $row['maturity_date']->toDateString()]);
            $record->forceFill(['maturity_reminder_sent_at' => now()])->saveQuietly();
            $sent++;
        }

        return $sent;
    }
}
