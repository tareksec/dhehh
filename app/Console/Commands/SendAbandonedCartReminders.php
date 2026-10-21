<?php

namespace App\Console\Commands;

use App\Models\StorefrontCartRecord;
use App\Models\StorefrontSetting;
use App\Services\StorefrontNotificationService;
use Illuminate\Console\Command;

class SendAbandonedCartReminders extends Command
{
    protected $signature = 'storefront:send-abandoned-cart-reminders';

    protected $description = 'Send SMS/WhatsApp reminders for abandoned storefront carts with a known customer phone';

    /**
     * A cart left longer ago than this (on top of the company's delay) gets no
     * reminder: when the scheduler starts after a long stop, old carts are not
     * all messaged at once (owner decision 2026-10-21).
     */
    public const STALE_AFTER_DAYS = 3;

    public function handle(StorefrontNotificationService $notifications): int
    {
        // Carts belong to the Blade storefront; the Next.js site keeps its cart in the browser.
        $settings = StorefrontSetting::withoutGlobalScopes()
            ->blade()
            ->with('company')
            ->where('is_published', true)
            ->where('abandoned_cart_reminders_enabled', true)
            ->get();

        $sent = 0;

        foreach ($settings as $setting) {
            $delayHours = max(1, (int) ($setting->abandoned_cart_delay_hours ?: 6));

            $carts = StorefrontCartRecord::withoutGlobalScopes()
                ->where('company_id', $setting->company_id)
                ->whereIn('status', [
                    StorefrontCartRecord::STATUS_ACTIVE,
                    StorefrontCartRecord::STATUS_CHECKOUT_STARTED,
                ])
                ->whereNotNull('phone')
                ->whereNull('reminded_at')
                ->where('updated_at', '<=', now()->subHours($delayHours))
                ->where('updated_at', '>=', now()->subHours($delayHours)->subDays(self::STALE_AFTER_DAYS))
                ->get();

            foreach ($carts as $cart) {
                $companyName = $setting->company?->name ?? 'our store';
                $recoveryUrl = $cart->recoveryUrl();
                $message = 'Hello'.($cart->customer_name ? " {$cart->customer_name}" : '').", you left items in your cart at {$companyName}. Complete your order any time - your cart is saved."
                    .($recoveryUrl ? " Restore it here: {$recoveryUrl}" : '');

                $smsSent = $notifications->sendSms($setting, $cart->phone, $message);
                $recoveryTemplate = trim((string) data_get($setting->notification_credentials, 'whatsapp_recovery_template_name'));
                $whatsAppParameters = [
                    $cart->customer_name ?: 'Customer',
                    $companyName,
                ];

                if ($recoveryTemplate !== '' && $recoveryUrl) {
                    $whatsAppParameters[] = $recoveryUrl;
                }

                $whatsAppSent = $notifications->sendWhatsAppTemplate(
                    $setting,
                    $cart->phone,
                    $whatsAppParameters,
                    $recoveryTemplate !== '' && $recoveryUrl ? $recoveryTemplate : null,
                );

                if ($smsSent || $whatsAppSent) {
                    $channels = array_values(array_filter([
                        $smsSent ? 'sms' : null,
                        $whatsAppSent ? 'whatsapp' : null,
                    ]));
                    $cart->update([
                        'status' => StorefrontCartRecord::STATUS_REMINDED,
                        'reminded_at' => now(),
                        'reminder_count' => $cart->reminder_count + 1,
                        'last_reminder_channels' => $channels,
                    ]);
                    $sent++;
                }
            }
        }

        $this->info("Abandoned cart reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
