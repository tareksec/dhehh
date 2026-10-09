<?php

namespace App\Filament\Resources\Vouchers\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Concerns\HasDrilldownStatCards;
use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\FundTransfer;
use App\Models\Voucher;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Dashboard-style summary cards at the top of the Vouchers page: one card
 * per (Credit Voucher | Debit Voucher | Fund Transfer) x (Requested |
 * Approved | Rejected) combination, each showing that combination's live
 * count. "Requested" groups Voucher's `pending` + `verified` statuses
 * together (both are pre-final-decision states in the approval workflow -
 * Credit vouchers normally pass through `verified` before `approved`, Debit
 * vouchers can go straight from `pending` to `approved` - see
 * VoucherWorkflowTest), matching the 3 buckets the owner asked for rather
 * than Voucher::STATUSES' full 5. `cancelled` is deliberately not its own
 * card, same reasoning.
 *
 * Each card opens a preview modal listing the matching vouchers/fund
 * transfers (owner request: same drilldown UX as the main Dashboard's own
 * cards - see App\Filament\Widgets\BusinessOverview and
 * App\Filament\Concerns\HasDrilldownStatCards), with a "See all" link
 * through to this same Vouchers page pre-filtered to that exact
 * combination. That filtered link does NOT use Filament's native
 * `tableFilters` query-string hydration - CourierMerchantDashboard's
 * docblock records that being confirmed broken on a cold GET in this
 * Filament install. Instead it reuses the plain-query-string +
 * `#[Url]`-bound-property pattern ProductStatsOverview's "Total Shortage"
 * card already ships with (BulkUpdateStock's `$shortageOnly`): ListVouchers
 * reads `cardType`/`cardStatuses`, FundTransfersWidget reads `ftStatus`,
 * both applied as a plain `->where()`/`->whereIn()` on the query, not
 * through Filament's filter UI state at all.
 */
class VoucherSummaryWidget extends StatsOverviewWidget implements HasActions
{
    use GuardsWidgetRendering;
    use HasDrilldownStatCards;
    use InteractsWithActions;

    // Filament widgets lazy-load (AJAX, after the page's first paint) by
    // default - fine for a normal browser visit, but these cards are the
    // whole point of the page, so render them synchronously on first load
    // instead, matching FundTransfersWidget/ProductStatsOverview's own
    // `$isLazy = false` on the same page/pattern.
    protected static bool $isLazy = false;

    // Reuses BusinessOverview's generic view: byte-identical
    // stats-overview-widget markup plus the <x-filament-actions::modals />
    // these drilldown cards need to actually render their preview popup.
    protected string $view = 'filament.widgets.business-overview';

    /**
     * @return array<string, int>
     */
    protected function getColumns(): array
    {
        return [
            'default' => 2,
            'lg' => 5,
        ];
    }

    protected function getStats(): array
    {
        $voucherCounts = Voucher::query()
            ->selectRaw('type, status, count(*) as aggregate')
            ->groupBy('type', 'status')
            ->get()
            ->groupBy('type')
            ->map(fn (Collection $rows): Collection => $rows->pluck('aggregate', 'status'));

        $fundTransferCounts = FundTransfer::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $stats = [];

        foreach (Voucher::TYPES as $type => $label) {
            $byStatus = $voucherCounts->get($type, collect());
            $requested = (int) $byStatus->get(Voucher::STATUS_PENDING, 0) + (int) $byStatus->get(Voucher::STATUS_VERIFIED, 0);
            $approved = (int) $byStatus->get(Voucher::STATUS_APPROVED, 0);
            $rejected = (int) $byStatus->get(Voucher::STATUS_REJECTED, 0);

            $icon = $type === Voucher::TYPE_CREDIT ? Heroicon::OutlinedDocumentPlus : Heroicon::OutlinedDocumentMinus;

            $stats[] = Stat::make("{$label} - Requested", $requested)
                ->icon($icon)
                ->color('warning')
                ->extraAttributes(['wire:click' => "mountAction('viewVoucherGroup', { type: '{$type}', bucket: 'requested' })"], merge: true);
            $stats[] = Stat::make("{$label} - Approved", $approved)
                ->icon($icon)
                ->color('success')
                ->extraAttributes(['wire:click' => "mountAction('viewVoucherGroup', { type: '{$type}', bucket: 'approved' })"], merge: true);
            $stats[] = Stat::make("{$label} - Rejected", $rejected)
                ->icon($icon)
                ->color('danger')
                ->extraAttributes(['wire:click' => "mountAction('viewVoucherGroup', { type: '{$type}', bucket: 'rejected' })"], merge: true);
        }

        $stats[] = Stat::make('Fund Transfer - Requested', (int) $fundTransferCounts->get(FundTransfer::STATUS_PENDING, 0))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('warning')
            ->extraAttributes(['wire:click' => "mountAction('viewFundTransferGroup', { status: '".FundTransfer::STATUS_PENDING."' })"], merge: true);
        $stats[] = Stat::make('Fund Transfer - Approved', (int) $fundTransferCounts->get(FundTransfer::STATUS_APPROVED, 0))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('success')
            ->extraAttributes(['wire:click' => "mountAction('viewFundTransferGroup', { status: '".FundTransfer::STATUS_APPROVED."' })"], merge: true);
        $stats[] = Stat::make('Fund Transfer - Rejected', (int) $fundTransferCounts->get(FundTransfer::STATUS_REJECTED, 0))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('danger')
            ->extraAttributes(['wire:click' => "mountAction('viewFundTransferGroup', { status: '".FundTransfer::STATUS_REJECTED."' })"], merge: true);

        // Owner: these 3-part labels ("Credit Voucher - Requested") wrap to
        // 3 lines at Filament's default stat-card text size, making the
        // cards look awkwardly tall on mobile — same compacting technique
        // BusinessOverview already uses for the main Dashboard's own cards
        // (see .zz-voucher-summary-stat in theme.css).
        foreach ($stats as $stat) {
            $stat->extraAttributes(['class' => 'zz-voucher-summary-stat'], merge: true);
        }

        return $stats;
    }

    public function viewVoucherGroupAction(): Action
    {
        return $this->drilldownModal(
            Action::make('viewVoucherGroup')
                ->modalHeading(fn (array $arguments): string => (Voucher::TYPES[$arguments['type'] ?? ''] ?? 'Voucher').' - '.$this->bucketLabel($arguments['bucket'] ?? ''))
                ->modalContent(function (array $arguments) {
                    $type = $arguments['type'] ?? '';
                    $statuses = $this->bucketStatuses($arguments['bucket'] ?? '');

                    $query = fn () => Voucher::query()->where('type', $type)->whereIn('status', $statuses);

                    return $this->drilldownView(
                        rows: $query()->latest()->limit($this->drilldownLimit)->get(),
                        total: $query()->count(),
                        columns: [
                            'Voucher #' => fn (Voucher $voucher): string => $voucher->voucher_number ?? '—',
                            'Account' => fn (Voucher $voucher): string => $voucher->account?->name ?? '—',
                            'Purpose' => fn (Voucher $voucher): string => $voucher->purpose ?: ($voucher->remarks ?: '—'),
                            'Amount' => fn (Voucher $voucher): string => MoneyFormatter::currency((float) $voucher->amount),
                        ],
                        emptyMessage: 'No matching vouchers.',
                        seeAllUrl: $this->voucherUrl($type, $statuses),
                        seeAllLabel: 'Open vouchers',
                    );
                })
        );
    }

    public function viewFundTransferGroupAction(): Action
    {
        return $this->drilldownModal(
            Action::make('viewFundTransferGroup')
                ->modalHeading(fn (array $arguments): string => 'Fund Transfer - '.(FundTransfer::STATUSES[$arguments['status'] ?? ''] ?? 'Requested'))
                ->modalContent(function (array $arguments) {
                    $status = $arguments['status'] ?? FundTransfer::STATUS_PENDING;
                    $query = fn () => FundTransfer::query()->where('status', $status);

                    return $this->drilldownView(
                        rows: $query()->latest()->limit($this->drilldownLimit)->get(),
                        total: $query()->count(),
                        columns: [
                            'Transfer #' => fn (FundTransfer $transfer): string => $transfer->transfer_number ?? '—',
                            'From' => fn (FundTransfer $transfer): string => $transfer->fromAccount?->name ?? '—',
                            'To' => fn (FundTransfer $transfer): string => $transfer->toAccount?->name ?? '—',
                            'Amount' => fn (FundTransfer $transfer): string => MoneyFormatter::currency((float) $transfer->amount),
                        ],
                        emptyMessage: 'No matching fund transfers.',
                        seeAllUrl: $this->fundTransferUrl($status),
                        seeAllLabel: 'Open fund transfers',
                    );
                })
        );
    }

    /**
     * @return array<int, string>
     */
    protected function bucketStatuses(string $bucket): array
    {
        return match ($bucket) {
            'requested' => [Voucher::STATUS_PENDING, Voucher::STATUS_VERIFIED],
            'approved' => [Voucher::STATUS_APPROVED],
            'rejected' => [Voucher::STATUS_REJECTED],
            default => [],
        };
    }

    protected function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'requested' => 'Requested',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => ucfirst($bucket),
        };
    }

    /**
     * @param  array<int, string>  $statuses
     */
    protected function voucherUrl(string $type, array $statuses): string
    {
        return VoucherResource::getUrl('index').'?'.http_build_query([
            'cardType' => $type,
            'cardStatuses' => implode(',', $statuses),
        ]);
    }

    protected function fundTransferUrl(string $status): string
    {
        return VoucherResource::getUrl('index').'?'.http_build_query(['ftStatus' => $status]);
    }
}
