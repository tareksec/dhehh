<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Services\AgreementService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * The agreements this person has to accept (investor agreement, partner
 * agreement), with "I have read and agree" recorded with time, IP and
 * device, and the ones already accepted.
 */
class Agreements extends Page
{
    use InvestorPortalPage;

    protected static ?string $portalRole = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.investor.pages.agreements';

    public static function getNavigationLabel(): string
    {
        return __('Agreements');
    }

    public function getTitle(): string
    {
        return __('Agreements');
    }

    /** @return Collection<int, AgreementTemplate> */
    public function outstanding(): Collection
    {
        return app(AgreementService::class)->outstanding($this->account());
    }

    /** @return Collection<int, AgreementAcceptance> */
    public function accepted(): Collection
    {
        return AgreementAcceptance::query()
            ->where('portal_account_id', $this->account()->getKey())
            ->with('template')
            ->orderByDesc('accepted_at')
            ->get();
    }

    public function accept(int $templateId): void
    {
        $template = $this->outstanding()->firstWhere('id', $templateId);

        if (! $template) {
            return;
        }

        app(AgreementService::class)->accept($this->account(), $template);

        Notification::make()->title(__('Thank you. Your acceptance is recorded.'))->success()->send();
    }
}
