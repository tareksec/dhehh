<?php

namespace App\Filament\Resources\InvestmentProjects\Pages;

use App\Filament\Resources\InvestmentProjects\InvestmentProjectResource;
use App\Filament\Resources\ProjectSettlements\ProjectSettlementResource;
use App\Models\InvestmentProject;
use App\Models\ProjectSettlement;
use App\Services\Investment\SettlementService;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;

class ViewInvestmentProject extends ViewRecord
{
    protected static string $resource = InvestmentProjectResource::class;

    /** @return list<string> */
    protected function unprovenCostLabels(): array
    {
        return $this->record->costItems()
            ->whereNull('purchase_id')
            ->withCount('documents')
            ->get()
            ->filter(fn ($item): bool => (int) $item->documents_count === 0)
            ->pluck('label')
            ->all();
    }

    /** Why the warning matters, in plain words. */
    public static function capitalGapMessage(InvestmentProject $project): string
    {
        $gap = (float) $project->capitalBaseGap();

        return __('Investors ৳:investors + company ৳:company = capital base ৳:base, but the landed cost is ৳:landed (:direction by ৳:gap). The profit per lakh is spread over the capital base, so it will differ from a figure worked out on the landed cost. Check the company contribution before settling.', [
            'investors' => MoneyFormatter::number($project->totalInvested()),
            'company' => MoneyFormatter::number((float) $project->company_contribution_amount),
            'base' => MoneyFormatter::number($project->totalCapitalBase()),
            'landed' => MoneyFormatter::number($project->totalLandedCost()),
            'direction' => $gap > 0 ? __('the base is higher') : __('the base is lower'),
            'gap' => MoneyFormatter::number(abs($gap)),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('register')
                ->label('Investor Register')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->url(fn (): string => route('investments.reports.project-register', $this->record))
                ->openUrlInNewTab(),
            Action::make('settle')
                ->label('Calculate & Settle')
                ->icon('heroicon-o-calculator')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === 'closed' && (auth()->user()?->hasPermission('investments.settle') ?? false))
                ->requiresConfirmation()
                ->modalDescription('This creates a DRAFT settlement and payout schedule from the recorded direct costs. Review it, then Confirm (or Void and recalculate).')
                ->schema([
                    Callout::make(__('The capital base is not the landed cost'))
                        ->description(fn (): string => static::capitalGapMessage($this->record))
                        ->status('warning')
                        ->visible(fn (): bool => $this->record->capitalBaseGap() !== null)
                        ->columnSpanFull(),
                    TextInput::make('total_revenue')->numeric()->prefix('৳')->minValue(0)->required()->live(debounce: 500)
                        ->helperText(fn (Get $get): string => 'বাস্তব বিক্রয় মূল্য (Selling Amount)। Direct cost: '
                            .MoneyFormatter::currency($this->record->totalCostItems())
                            .' → Net: '.MoneyFormatter::currency((float) ($get('total_revenue') ?? 0) - $this->record->totalCostItems())),
                    Select::make('outcome')
                        // A group's principal stays invested, so a loss that eats into it waits for the owner's rule.
                        ->options(fn (): array => $this->record->isGrouped() ? array_diff_key(ProjectSettlement::OUTCOMES, ['loss_investor_borne' => true]) : ProjectSettlement::OUTCOMES)
                        ->default('profit')
                        ->required()
                        ->live()
                        ->helperText('Net profit নেগেটিভ হলে চুক্তির ধারা ৪ অনুযায়ী লোকসান কে বহন করবে বেছে নিন।'),
                    Textarea::make('loss_reason')
                        ->rows(2)
                        ->required(fn (Get $get): bool => $get('outcome') !== 'profit')
                        ->visible(fn (Get $get): bool => $get('outcome') !== 'profit')
                        ->helperText('কেন লোকসান হলো — সংক্ষিপ্ত বিবরণ (audit-এ থাকবে)।'),
                    Checkbox::make('acknowledge_unproven_costs')
                        ->label('Some cost items have no purchase link or receipt — settle anyway')
                        ->visible(fn (): bool => $this->unprovenCostLabels() !== [])
                        ->helperText(fn (): string => 'প্রমাণ ছাড়া cost item: '.implode(', ', $this->unprovenCostLabels())),
                ])
                ->action(function (array $data): void {
                    $settlement = app(SettlementService::class)->calculateAndSettle(
                        $this->record,
                        (float) $data['total_revenue'],
                        (int) auth()->id(),
                        (bool) ($data['acknowledge_unproven_costs'] ?? false),
                        (string) ($data['outcome'] ?? 'profit'),
                        $data['loss_reason'] ?? null,
                    );
                    Notification::make()->success()->title('Draft settlement created')->body('Net profit: '.MoneyFormatter::currency((float) $settlement->net_profit).' — review and confirm.')->send();
                    $this->redirect(ProjectSettlementResource::getUrl('view', ['record' => $settlement]));
                }),
        ];
    }
}
