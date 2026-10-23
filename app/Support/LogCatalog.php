<?php

namespace App\Support;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\CourierStatusLogs\CourierStatusLogResource;
use App\Filament\Resources\CourierWebhookLogs\CourierWebhookLogResource;
use App\Filament\Resources\DeploymentErrors\DeploymentErrorResource;
use App\Filament\Resources\MobileCrashReports\MobileCrashReportResource;
use App\Filament\Resources\SmsLogs\SmsLogResource;
use App\Filament\Resources\StorefrontMetaEvents\StorefrontMetaEventResource;
use Filament\Support\Icons\Heroicon;

/**
 * The logs that live on the one Logs page (Settings → Logs, owner request
 * 2026-10-23), one tab each. A tab shows the table its own resource already
 * defines, and only to people that resource lets in (`canViewAny()`), so
 * gathering the logs in one place changes nobody's access. The resources keep
 * their own pages and URLs (links from other screens still work); only their
 * sidebar entries are gone.
 */
final class LogCatalog
{
    /**
     * @return array<string, array{label: string, icon: Heroicon, resource: class-string}>
     */
    public static function all(): array
    {
        return [
            'sms' => ['label' => __('SMS'), 'icon' => Heroicon::OutlinedChatBubbleBottomCenterText, 'resource' => SmsLogResource::class],
            'audit' => ['label' => __('Audit'), 'icon' => Heroicon::OutlinedClipboardDocumentCheck, 'resource' => AuditLogResource::class],
            'courier_status' => ['label' => __('Courier status'), 'icon' => Heroicon::OutlinedListBullet, 'resource' => CourierStatusLogResource::class],
            'courier_webhooks' => ['label' => __('Courier webhooks'), 'icon' => Heroicon::OutlinedSignal, 'resource' => CourierWebhookLogResource::class],
            'meta_events' => ['label' => __('Meta events'), 'icon' => Heroicon::OutlinedChartBar, 'resource' => StorefrontMetaEventResource::class],
            'deploy_errors' => ['label' => __('Deploy errors'), 'icon' => Heroicon::OutlinedExclamationTriangle, 'resource' => DeploymentErrorResource::class],
            'mobile_crashes' => ['label' => __('Mobile crashes'), 'icon' => Heroicon::OutlinedDevicePhoneMobile, 'resource' => MobileCrashReportResource::class],
        ];
    }

    /**
     * The tabs the signed-in user may open.
     *
     * @return array<string, array{label: string, icon: Heroicon, resource: class-string}>
     */
    public static function visible(): array
    {
        return array_filter(self::all(), fn (array $log): bool => $log['resource']::canViewAny());
    }

    /** @return class-string|null the resource behind a tab, only when the user may open it */
    public static function resource(string $key): ?string
    {
        return self::visible()[$key]['resource'] ?? null;
    }
}
