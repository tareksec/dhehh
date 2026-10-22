<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Crm;
use App\Services\Crm\ToChaseService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * People who wanted to buy and did not (16_AI_INBOX_CONTROL_PLANE_PLAN.md
 * §4.8): carts left behind, checkouts stopped at the payment, leads with no
 * order. Worked out each time from the data; only "contacted" is stored.
 */
class ToChase extends Page
{
    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowUpRight;

    protected string $view = 'filament.pages.to-chase';

    public int $days = 7;

    /** @var list<string> */
    public array $sources = ['cart', 'checkout', 'lead'];

    public bool $includeContacted = false;

    public static function getNavigationLabel(): string
    {
        return __('To chase');
    }

    public function getTitle(): string
    {
        return __('To chase');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('crm.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function canManage(): bool
    {
        return auth()->user()?->hasPermission('crm.manage') ?? false;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function rows(): Collection
    {
        $days = in_array($this->days, ToChaseService::DAY_CHOICES, true) ? $this->days : 7;

        return app(ToChaseService::class)->rows($days, $this->sources, $this->includeContacted);
    }

    public function markContacted(string $phone): void
    {
        abort_unless($this->canManage(), 403);
        app(ToChaseService::class)->markContacted($phone, auth()->user());
        Notification::make()->title(__('Marked as contacted.'))->success()->send();
    }

    public function undoContacted(string $phone): void
    {
        abort_unless($this->canManage(), 403);
        app(ToChaseService::class)->unmarkContacted($phone);
    }
}
