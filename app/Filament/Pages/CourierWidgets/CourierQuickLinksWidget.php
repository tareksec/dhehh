<?php

namespace App\Filament\Pages\CourierWidgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Pages\CourierMerchantDashboard;
use App\Filament\Pages\CourierPaymentHistory;
use App\Filament\Resources\CourierBookings\CourierBookingResource;
use App\Filament\Resources\CourierReturns\CourierReturnResource;
use App\Filament\Resources\CourierStatusLogs\CourierStatusLogResource;
use App\Filament\Resources\CourierWebhookLogs\CourierWebhookLogResource;
use App\Models\CourierBooking;
use App\Models\CourierReturn;
use App\Models\CourierStatusLog;
use App\Models\CourierWebhookLog;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Card-grid quick links to the Bookings/Status Logs/Returns/Webhook
 * Logs/Payments screens, using Filament's native Stat card component so
 * they render with the panel's standard widget styling. Deliberately kept
 * outside app/Filament/Widgets (which is auto-discovered and shown on the
 * main admin Dashboard) — this widget is embedded directly into the
 * Courier Merchant Dashboard view only.
 *
 * Provider-aware: the parent page passes its selected courier's id (or null
 * for "All Couriers") via `@livewire(..., ['providerId' => ...], key(...))`.
 * The `key()` changing when the selection changes is required — a nested
 * `@livewire()` component is its own independent Livewire instance and does
 * NOT re-`mount()` just because the parent re-renders with a different
 * array value; only a changed key forces Livewire to treat it as a new
 * instance and re-run `mount()` with the fresh param. Payments stays
 * unscoped — it's a single Steadfast-only settlement-history page, not a
 * per-provider count.
 */
class CourierQuickLinksWidget extends StatsOverviewWidget
{
    use GuardsWidgetRendering;

    public ?int $providerId = null;

    public function mount(?int $providerId = null): void
    {
        $this->providerId = $providerId;
    }

    /**
     * Matches the Booking Status Summary card grid above it on the
     * dashboard (5 per row on desktop, 2 per row on mobile) instead of the
     * base widget's auto-computed column count, so the two sections read as
     * one consistent layout.
     */
    protected function getColumns(): int|array|null
    {
        return ['default' => 2, 'lg' => 5];
    }

    protected function getStats(): array
    {
        $bookings = CourierBooking::query()->when($this->providerId, fn ($q, int $id) => $q->where('courier_provider_id', $id))->count();
        $statusLogs = CourierStatusLog::query()->when($this->providerId, fn ($q, int $id) => $q->whereHas('booking', fn ($bq) => $bq->where('courier_provider_id', $id)))->count();
        $returns = CourierReturn::query()->when($this->providerId, fn ($q, int $id) => $q->where('courier_provider_id', $id))->count();
        $failedWebhooks = CourierWebhookLog::query()->when($this->providerId, fn ($q, int $id) => $q->where('courier_provider_id', $id))->where('status', 'failed')->count();
        $webhookColor = $failedWebhooks > 0 ? 'danger' : 'gray';

        return [
            Stat::make('Bookings', number_format($bookings))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('primary')
                ->extraAttributes(['class' => $this->tint('primary')])
                ->url(CourierBookingResource::getUrl()),

            Stat::make('Status Logs', number_format($statusLogs))
                ->icon(Heroicon::OutlinedListBullet)
                ->color('info')
                ->extraAttributes(['class' => $this->tint('info')])
                ->url(CourierStatusLogResource::getUrl()),

            Stat::make('Returns', number_format($returns))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('warning')
                ->extraAttributes(['class' => $this->tint('warning')])
                ->url(CourierReturnResource::getUrl()),

            // The description text is gone (per the dashboard's card design),
            // but a failed-webhook signal still needs to survive somewhere —
            // it now lives entirely in the card's color switching to danger.
            Stat::make('Webhook Logs', number_format($failedWebhooks))
                ->icon(Heroicon::OutlinedSignal)
                ->color($webhookColor)
                ->extraAttributes(['class' => $this->tint($webhookColor)])
                ->url(CourierWebhookLogResource::getUrl()),

            Stat::make('Payments', 'View')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->extraAttributes(['class' => $this->tint('success')])
                ->url(CourierPaymentHistory::getUrl()),
        ];
    }

    /**
     * Shares the exact same tint palette as the Booking Status Summary
     * cards above this widget — see `CourierMerchantDashboard::statCardTint()`
     * for why the `!` (Tailwind important) prefix is required.
     */
    protected function tint(string $color): string
    {
        return implode(' ', CourierMerchantDashboard::statCardTint($color));
    }
}
