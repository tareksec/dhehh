<?php

namespace App\Filament\Ims\Portal\Pages;

use App\Filament\Concerns\HasGuardedNavigationBadge;
use App\Filament\Investor\Concerns\InvestorPortalPage;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/** The investor's news list (IMS-V2/PRD.md FR-60): newest first, "mark all read". */
class Notifications extends Page
{
    use HasGuardedNavigationBadge;
    use InvestorPortalPage;

    protected static ?string $portalRole = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.ims.portal.notifications';

    public static function getNavigationGroup(): ?string
    {
        return __('Overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('Notifications');
    }

    protected static function navigationBadge(): ?string
    {
        $count = static::unreadCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function unreadCount(): int
    {
        $account = filament()->auth()->user();

        return $account && method_exists($account, 'unreadNotifications') ? $account->unreadNotifications()->count() : 0;
    }

    public function getTitle(): string
    {
        return __('Notifications');
    }

    /** @return Collection<int, DatabaseNotification> */
    public function items(): Collection
    {
        return $this->account()->notifications()->latest()->limit(100)->get();
    }

    public function markAllRead(): void
    {
        $this->refuseInStaffPreview();
        $this->account()->unreadNotifications()->update(['read_at' => now()]);
    }
}
