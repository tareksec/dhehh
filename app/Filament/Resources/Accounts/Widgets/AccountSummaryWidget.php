<?php

namespace App\Filament\Resources\Accounts\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Concerns\HasDrilldownStatCards;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\TransactionLedger;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard-style summary cards at the top of the Accounts page: one card
 * per account (every account that exists, not just active ones - the owner
 * asked for however many are "created"), showing its live current balance.
 * Clicking a card opens a preview modal of that account's recent ledger
 * activity (owner request: same drilldown UX as the main Dashboard's own
 * cards - see App\Filament\Widgets\BusinessOverview and
 * App\Filament\Concerns\HasDrilldownStatCards), with a "See all" link
 * through to that exact account's own page (AccountResource's `view`)
 * inside the modal.
 */
class AccountSummaryWidget extends StatsOverviewWidget implements HasActions
{
    use GuardsWidgetRendering;
    use HasDrilldownStatCards;
    use InteractsWithActions;

    // See VoucherSummaryWidget's docblock: these cards are the point of the
    // page, so they render synchronously instead of lazy/AJAX-loading.
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
        return Account::query()
            ->orderBy('name')
            ->get()
            ->map(function (Account $account): Stat {
                $balance = $account->balance();

                return Stat::make($account->name, MoneyFormatter::currency($balance))
                    ->icon(Heroicon::OutlinedWallet)
                    ->color($balance < 0 ? 'danger' : 'success')
                    ->extraAttributes([
                        // Owner: compact these cards the same way as
                        // Vouchers' (see .zz-account-summary-stat in
                        // theme.css) — long account names wrapped and made
                        // the cards look tall.
                        'class' => 'zz-account-summary-stat',
                        // Only the account's primary key (an integer) is
                        // ever embedded here — never the account's own
                        // name/text, which would need JS-string escaping.
                        // See HasDrilldownStatCards.
                        'wire:click' => "mountAction('viewAccount', { account: {$account->getKey()} })",
                    ], merge: true);
            })
            ->all();
    }

    public function viewAccountAction(): Action
    {
        return $this->drilldownModal(
            Action::make('viewAccount')
                ->modalHeading(fn (array $arguments): string => Account::query()->find($arguments['account'] ?? null)?->name ?? 'Account')
                ->modalContent(function (array $arguments) {
                    $account = Account::query()->find($arguments['account'] ?? null);

                    if (! $account) {
                        return $this->drilldownView(collect(), 0, [], 'This account no longer exists.');
                    }

                    $query = fn () => TransactionLedger::query()->where('account_id', $account->getKey());

                    return $this->drilldownView(
                        rows: $query()->latest('transaction_date')->latest('id')->limit($this->drilldownLimit)->get(),
                        total: $query()->count(),
                        columns: [
                            'Date' => fn (TransactionLedger $ledger): string => $ledger->transaction_date?->format('d M Y') ?? '—',
                            'Type' => fn (TransactionLedger $ledger): string => TransactionLedger::TYPES[$ledger->type] ?? $ledger->type,
                            'Note' => fn (TransactionLedger $ledger): string => $ledger->note ?? '—',
                            'Amount' => fn (TransactionLedger $ledger): string => ($ledger->direction === 'out' ? '-' : '+').MoneyFormatter::currency((float) $ledger->amount),
                        ],
                        emptyMessage: 'No transactions recorded for this account yet.',
                        seeAllUrl: AccountResource::getUrl('view', ['record' => $account]),
                        seeAllLabel: 'Open account',
                    );
                })
        );
    }
}
