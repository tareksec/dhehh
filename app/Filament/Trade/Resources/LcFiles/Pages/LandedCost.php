<?php

namespace App\Filament\Trade\Resources\LcFiles\Pages;

use App\Filament\Trade\Resources\LcFiles\LcFileResource;
use App\Filament\Trade\TradeErrors;
use App\Models\LandedCostLine;
use App\Models\LcFile;
use App\Services\LandedCostService;
use App\Support\ImsNumbers;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The landed cost worksheet of an LC file (SUMMARY.md §4 report 3): the goods
 * in Taka at the bank's rate, every cost with where its figure came from, the
 * total, the cost per unit and per item, and "Apply to stock buying price".
 * Needs "trade.landed_cost". The figures come from LandedCostService.
 */
class LandedCost extends Page
{
    use InteractsWithRecord;

    protected static string $resource = LcFileResource::class;

    protected string $view = 'filament.trade.lc-files.landed-cost';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::canAccess(), 403);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->hasPermission('trade.landed_cost') ?? false;
    }

    public function getTitle(): string
    {
        return __('Landed cost');
    }

    public function getSubheading(): ?string
    {
        $file = $this->file();

        return filled($file->purchase?->lc_number) ? __('LC :number', ['number' => $file->purchase->lc_number]) : ($file->purchase?->supplier?->name);
    }

    public function file(): LcFile
    {
        return $this->getRecord();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['sheet' => app(LandedCostService::class)->worksheet($this->file()->refresh())];
    }

    /** @return array<string, string> */
    public static function kindOptions(): array
    {
        return collect(LandedCostLine::KINDS)->map(fn (array $kind): string => __($kind[0]))->all();
    }

    /** @return array<string, string> */
    public static function sourceOptions(): array
    {
        return collect(LandedCostLine::SOURCES)->map(fn (string $label): string => __($label))->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addLine')
                ->label(__('Add a cost'))
                ->icon('heroicon-m-plus')
                ->schema([
                    Select::make('kind')
                        ->label(__('What for'))
                        ->options(static::kindOptions())
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('source', LandedCostLine::KINDS[$state][1] ?? 'other')),
                    TextInput::make('label')->label(__('Name of the cost'))->required(fn (Get $get): bool => $get('kind') === 'other')->visible(fn (Get $get): bool => $get('kind') === 'other')->maxLength(255),
                    TextInput::make('amount')->label(__('Amount (Taka)'))->numeric()->minValue(1)->required(),
                    Select::make('source')->label(__('Figure from'))->options(static::sourceOptions())->required(),
                    DatePicker::make('paid_on')->label(__('Paid on'))->maxDate(now()),
                    TextInput::make('note')->label(__('Note'))->placeholder(__('e.g. C&F bill no.'))->maxLength(255),
                ])
                ->action(function (array $data, Action $action): void {
                    TradeErrors::attempt(fn () => app(LandedCostService::class)->addLine($this->file(), $data))
                        ? Notification::make()->title(__('Cost added'))->success()->send()
                        : $action->halt();
                }),
            Action::make('setRate')
                ->label(__('Bank rate'))
                ->icon('heroicon-o-currency-dollar')
                ->color('gray')
                ->fillForm(fn (): array => ['exchange_rate' => $this->file()->exchange_rate])
                ->schema([
                    TextInput::make('exchange_rate')
                        ->label(__('Taka per US Dollar'))
                        ->helperText(__('The rate the bank settled the LC at. The goods cost becomes the LC value × this rate.'))
                        ->numeric()
                        ->minValue(0.0001),
                ])
                ->action(function (array $data, Action $action): void {
                    TradeErrors::attempt(fn () => app(LandedCostService::class)->setRate($this->file(), filled($data['exchange_rate'] ?? null) ? (float) $data['exchange_rate'] : null))
                        ? Notification::make()->title(__('Rate saved'))->success()->send()
                        : $action->halt();
                }),
            Action::make('apply')
                ->label(__('Apply to stock buying price'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                // Each item gets its own share, not the blended cost per unit, so the window names each new price.
                ->modalDescription(fn (): string => __('Each item\'s buying price becomes its own landed cost per unit: :items. The stock value and the profit then use it. You can apply again after adding costs.', [
                    'items' => collect(app(LandedCostService::class)->worksheet($this->file())['items'])
                        ->filter(fn (array $item): bool => $item['landed_unit'] > 0)
                        ->map(fn (array $item): string => $item['product'].' '.ImsNumbers::money($item['landed_unit']))
                        ->implode('; '),
                ]))
                ->action(function (Action $action): void {
                    $changed = 0;

                    TradeErrors::attempt(function () use (&$changed): void {
                        $changed = app(LandedCostService::class)->applyToStock($this->file());
                    })
                        ? Notification::make()->title(__('Buying price updated for :count items', ['count' => $changed]))->success()->send()
                        : $action->halt();
                }),
        ];
    }

    public function deleteLineAction(): Action
    {
        return Action::make('deleteLine')
            ->label(__('Remove'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->size('sm')
            ->link()
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                $this->file()->landedCostLines()->whereKey((int) ($arguments['line'] ?? 0))->first()?->delete();
                Notification::make()->title(__('Cost removed'))->success()->send();
            });
    }
}
