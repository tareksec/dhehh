<?php

namespace App\Console\Commands;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\StorefrontSetting;
use App\Services\BusinessNotificationService;
use App\Services\Crm\ContactSuppressionService;
use App\Services\StorefrontNotificationService;
use Illuminate\Console\Command;

class SendLeadFollowUpReminders extends Command
{
    protected $signature = 'crm:send-follow-up-reminders';

    protected $description = 'Notify the assigned staff member and (optionally) message the lead when a CRM follow-up date arrives';

    /**
     * A follow-up due longer ago than this still reaches the staff member, but
     * the lead gets no message: when the scheduler starts after a long stop,
     * old leads are not all messaged at once (owner decision 2026-10-21).
     */
    public const STALE_AFTER_DAYS = 3;

    public function handle(
        BusinessNotificationService $businessNotifications,
        StorefrontNotificationService $storefrontNotifications,
    ): int {
        $leads = Lead::withoutGlobalScopes()->withoutTrashed()
            ->with(['company', 'assignedUser'])
            ->whereNotNull('assigned_to')
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', now())
            ->whereNull('follow_up_reminded_at')
            ->whereNotIn('status', ['won', 'lost'])
            ->get();

        $notified = 0;

        foreach ($leads as $lead) {
            if (! $lead->company || ! $lead->assignedUser) {
                continue;
            }

            $businessNotifications->notifyUser(
                $lead->assignedUser,
                'lead_follow_up_due',
                'Follow-up due: '.$lead->name,
                $lead->interest ? "Interest: {$lead->interest}" : 'A follow-up is due for this lead.',
                ['lead_id' => (string) $lead->getKey()],
                LeadResource::getUrl('edit', ['record' => $lead]),
                'Open lead',
            );

            if ($lead->next_follow_up_at->gte(now()->subDays(self::STALE_AFTER_DAYS))) {
                $this->messageLead($lead, $storefrontNotifications);
            }

            $lead->forceFill(['follow_up_reminded_at' => now()])->saveQuietly();
            $notified++;
        }

        $this->info("Follow-up reminders processed: {$notified}.");

        return self::SUCCESS;
    }

    protected function messageLead(Lead $lead, StorefrontNotificationService $notifications): void
    {
        if (app(ContactSuppressionService::class)->suppressed((int) $lead->company_id, (string) $lead->phone)) {
            return;
        }
        $phone = trim((string) $lead->phone);

        if ($phone === '') {
            return;
        }

        $setting = StorefrontSetting::primaryFor((int) $lead->company_id);

        if (! $setting || ! $setting->lead_follow_up_reminders_enabled) {
            return;
        }

        $template = trim((string) data_get($setting->notification_credentials, 'lead_follow_up_whatsapp_template_name'));
        $whatsAppSent = $template !== ''
            && $notifications->sendWhatsAppTemplate($setting, $phone, [$lead->name], $template);

        if ($whatsAppSent) {
            return;
        }

        $smsBody = trim((string) data_get($setting->notification_credentials, 'lead_follow_up_sms_body'));

        if ($smsBody === '') {
            return;
        }

        $notifications->sendSms(
            $setting,
            $phone,
            strtr($smsBody, [
                '{{name}}' => $lead->name,
                '{{company}}' => $lead->company?->name ?? 'us',
            ]),
        );
    }
}
