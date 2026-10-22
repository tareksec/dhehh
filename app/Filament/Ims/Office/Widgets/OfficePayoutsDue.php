<?php

namespace App\Filament\Ims\Office\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Ims\Office\OfficeFigures;
use App\Models\Investor;
use App\Services\CompanyContext;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * The office home's right-hand card: payouts still to send (with the profit
 * already paid this month), and the people whose KYC is waiting, as initials.
 */
class OfficePayoutsDue extends Widget
{
    use GuardsWidgetRendering;

    protected static bool $isLazy = false;

    protected string $view = 'filament.ims.office.widgets.payouts-due';

    /** @return array{due: float, paid: float, currency: string} */
    public function figures(): array
    {
        $figures = app(OfficeFigures::class);

        return [
            'due' => $figures->payoutsDue(),
            'paid' => $figures->profitPaidThisMonth(),
            'currency' => app(CompanyContext::class)->company()?->currency ?: 'BDT',
        ];
    }

    /** @return array{people: Collection<int, Investor>, total: int} */
    public function kycWaiting(): array
    {
        $query = Investor::query()->where('kyc_status', Investor::KYC_PENDING);

        return ['people' => (clone $query)->latest('id')->limit(4)->get(['id', 'name']), 'total' => $query->count()];
    }
}
