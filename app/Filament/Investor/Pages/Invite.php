<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\InvestorDraft;
use App\Models\InvestorPortalAccount;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Page 12 of §6.2: the channel partner's invite link (switched on by the
 * company) and the people who applied through it, with their status. No
 * contact details or documents of applicants are shown.
 */
class Invite extends Page
{
    use InvestorPortalPage;

    protected static ?string $portalRole = 'partner';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'invite-investors';

    protected string $view = 'filament.investor.pages.invite';

    public static function getNavigationGroup(): ?string
    {
        return __('Channel partner');
    }

    public static function getNavigationLabel(): string
    {
        return __('Invite investors');
    }

    public function getTitle(): string
    {
        return __('Invite investors');
    }

    public function inviteUrl(): ?string
    {
        return $this->account()->channelPartner?->inviteUrl();
    }

    /** @return Collection<int, array{name: string, date: Carbon, status: string}> */
    public function applicants(): Collection
    {
        $partnerId = $this->account()->channel_partner_id;

        $pending = InvestorDraft::query()
            ->where('source', InvestorDraft::SOURCE_PORTAL)
            ->where('channel_partner_id', $partnerId)
            ->get()
            ->map(fn (InvestorDraft $draft): array => ['name' => (string) $draft->name, 'date' => $draft->created_at, 'status' => __('Under review')]);

        $decided = InvestorPortalAccount::query()
            ->whereHas('investor', fn ($query) => $query->where('channel_partner_id', $partnerId))
            ->whereNotNull('investor_id')
            ->get()
            ->map(fn (InvestorPortalAccount $account): array => ['name' => $account->investor?->reportName() ?? $account->name, 'date' => $account->created_at, 'status' => __('Approved')]);

        return $pending->concat($decided)->sortByDesc('date')->values();
    }
}
