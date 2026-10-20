<?php

namespace App\Ims\Settings;

use App\Models\Company;

/**
 * IMS v2 settings of one company, kept under `companies.settings['ims']`
 * (IMS-V2/TRD.md §3.3 "ims_settings (or company setting)"). Every switch the
 * owner can change in the office's IMS Settings page; nothing here is a
 * business rule of its own, only which message goes which way and whether a
 * daily job runs.
 */
class ImsSettings
{
    /**
     * The news an investor can get (IMS-V2/PRD.md FR-62). In-portal is always
     * on (FR-60); SMS and e-mail are chosen per event.
     */
    public const EVENTS = [
        'investment_confirmed' => 'Investment confirmed',
        'profit_shared' => 'Profit shared',
        'loss_shared' => 'Loss shared',
        'payout_paid' => 'Payout paid',
        'capital_returned' => 'Capital paid back',
        'maturity_near' => 'Maturity is near',
        'monthly_statement' => 'Monthly statement',
        'project_report' => 'Project report published',
        'project_update' => 'Project update',
        'kyc_decided' => 'KYC approved or not accepted',
        'support_reply' => 'Support reply',
        'feed_post' => 'New feed post',
    ];

    /** Out of the box: the important money news by SMS, the details by e-mail. */
    public const DEFAULT_CHANNELS = [
        'investment_confirmed' => ['sms' => true, 'email' => true],
        'profit_shared' => ['sms' => true, 'email' => true],
        'loss_shared' => ['sms' => true, 'email' => true],
        'payout_paid' => ['sms' => true, 'email' => true],
        'capital_returned' => ['sms' => true, 'email' => true],
        'maturity_near' => ['sms' => true, 'email' => true],
        'monthly_statement' => ['sms' => false, 'email' => true],
        'project_report' => ['sms' => false, 'email' => true],
        'project_update' => ['sms' => false, 'email' => false],
        'kyc_decided' => ['sms' => true, 'email' => true],
        'support_reply' => ['sms' => false, 'email' => true],
        'feed_post' => ['sms' => false, 'email' => false],
    ];

    public const DEFAULTS = [
        // Joining by an office invite + one-time code (PRD FR-04): off until the owner turns it on.
        'invite_registration_enabled' => false,
        'maturity_reminder_days' => 7,
        'jobs' => [
            'profit_proposals' => true,
            'maturity_reminders' => true,
            'owner_digest' => true,
            'monthly_statements' => true,
            'shipment_delays' => true,
        ],
    ];

    /** @return array<string, mixed> */
    public static function all(Company $company): array
    {
        $saved = (array) (($company->settings ?? [])['ims'] ?? []);

        return array_replace_recursive(self::DEFAULTS, ['channels' => self::DEFAULT_CHANNELS], $saved);
    }

    public static function get(Company $company, string $key, mixed $default = null): mixed
    {
        return data_get(self::all($company), $key, $default);
    }

    public static function channelOn(Company $company, string $event, string $channel): bool
    {
        return (bool) data_get(self::all($company), "channels.{$event}.{$channel}", false);
    }

    public static function jobOn(Company $company, string $job): bool
    {
        return (bool) data_get(self::all($company), "jobs.{$job}", true);
    }

    /** @param  array<string, mixed>  $values */
    public static function save(Company $company, array $values): void
    {
        $settings = (array) ($company->settings ?? []);
        $settings['ims'] = array_replace_recursive((array) ($settings['ims'] ?? []), $values);

        $company->forceFill(['settings' => $settings])->save();
    }
}
