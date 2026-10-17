<?php

namespace App\Filament\Resources\Expenses\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Concerns\HasDrilldownStatCards;
use App\Filament\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard-style summary cards at the top of the Expenses page: one card
 * per expense category (every category that exists, same "show them all"
 * rule as AccountSummaryWidget), showing the total amount spent in that
 * category to date. Clicking a card opens a preview modal of that
 * category's recent expenses (owner request: same drilldown UX as the main
 * Dashboard's own cards - see App\Filament\Widgets\BusinessOverview and
 * App\Filament\Concerns\HasDrilldownStatCards), with a "See all" link
 * through to that exact category's own page (ExpenseCategoryResource's
 * `view`) inside the modal.
 */
class ExpenseCategorySummaryWidget extends StatsOverviewWidget implements HasActions
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
        return ExpenseCategory::query()
            ->withSum('expenses', 'amount')
            ->orderBy('name')
            ->get()
            ->map(fn (ExpenseCategory $category): Stat => Stat::make($category->name, MoneyFormatter::currency($category->expenses_sum_amount ?? 0))
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->color('danger')
                ->extraAttributes([
                    // Owner: compact these cards the same way as Vouchers'
                    // (see .zz-expense-summary-stat in theme.css) — long
                    // category names wrapped and made the cards look tall.
                    'class' => 'zz-expense-summary-stat',
                    // Only the category's primary key (an integer) is ever
                    // embedded here — never the category's own name/text,
                    // which would need JS-string escaping. See
                    // HasDrilldownStatCards.
                    'wire:click' => "mountAction('viewExpenseCategory', { category: {$category->getKey()} })",
                ], merge: true))
            ->all();
    }

    public function viewExpenseCategoryAction(): Action
    {
        return $this->drilldownModal(
            Action::make('viewExpenseCategory')
                ->modalHeading(fn (array $arguments): string => ExpenseCategory::query()->find($arguments['category'] ?? null)?->name ?? 'Expense Category')
                ->modalContent(function (array $arguments) {
                    $category = ExpenseCategory::query()->find($arguments['category'] ?? null);

                    if (! $category) {
                        return $this->drilldownView(collect(), 0, [], 'This category no longer exists.');
                    }

                    $query = fn () => Expense::query()->where('expense_category_id', $category->getKey());

                    return $this->drilldownView(
                        rows: $query()->latest('expense_date')->latest('id')->limit($this->drilldownLimit)->get(),
                        total: $query()->count(),
                        columns: [
                            'Date' => fn (Expense $expense): string => $expense->expense_date?->format('d M Y') ?? '—',
                            'Account' => fn (Expense $expense): string => $expense->account?->name ?? '—',
                            'Note' => fn (Expense $expense): string => $expense->note ?? '—',
                            'Amount' => fn (Expense $expense): string => MoneyFormatter::currency((float) $expense->amount),
                        ],
                        emptyMessage: 'No expenses recorded in this category yet.',
                        seeAllUrl: ExpenseCategoryResource::getUrl('view', ['record' => $category]),
                        seeAllLabel: 'Open category',
                    );
                })
        );
    }
}
