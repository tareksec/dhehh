<?php

namespace App\Filament\Trade\Resources\LcFiles\Pages;

use App\Filament\Trade\Resources\LcFiles\LcFileResource;
use App\Filament\Trade\TradeErrors;
use App\Models\LcFile;
use App\Services\LcFileService;
use App\Services\MachineSerialService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * One LC file: the stages with the current one marked, the main numbers, and
 * the five papers — each ready to print, or with what is still missing.
 */
class ViewLcFile extends ViewRecord
{
    protected static string $resource = LcFileResource::class;

    protected string $view = 'filament.trade.lc-files.view';

    public function getTitle(): string
    {
        $file = $this->getRecord();

        return filled($file->purchase?->lc_number)
            ? __('LC :number', ['number' => $file->purchase->lc_number])
            : __('LC file — :supplier', ['supplier' => $file->purchase?->supplier?->name ?? '—']);
    }

    public function getRecord(): LcFile
    {
        return parent::getRecord();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nextStage')
                ->label(fn (): string => __('Move to: :stage', ['stage' => LcFile::stageLabel($this->getRecord()->nextStage())]))
                ->icon('heroicon-o-arrow-right-circle')
                ->visible(fn (): bool => $this->getRecord()->nextStage() !== null)
                ->requiresConfirmation()
                ->action(function (Action $action): void {
                    TradeErrors::attempt(fn () => app(LcFileService::class)->nextStage($this->getRecord()))
                        ? Notification::make()->title(__('Stage changed'))->success()->send()
                        : $action->halt();
                }),
            Action::make('landedCost')
                ->label(__('Landed cost'))
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->visible(fn (): bool => LandedCost::canAccess())
                ->url(fn (): string => LcFileResource::getUrl('landed-cost', ['record' => $this->getRecord()])),
            Action::make('serials')
                ->label(__('Serial numbers'))
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->purchase?->items()->exists() ?? false)
                ->schema([
                    Select::make('product_id')
                        ->label(__('Item'))
                        ->options(fn (): array => $this->getRecord()->purchase->items()->with('product')->get()
                            ->mapWithKeys(fn ($item): array => [$item->product_id => ($item->product?->name ?? '—').' × '.(int) $item->quantity])
                            ->all())
                        ->required(),
                    Textarea::make('serials')
                        ->label(__('Serial numbers'))
                        ->helperText(__('One per line. Optional: write them only for machines that have one.'))
                        ->rows(6)
                        ->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    $added = 0;

                    TradeErrors::attempt(function () use ($data, &$added): void {
                        $added = app(MachineSerialService::class)->addToPurchase($this->getRecord()->purchase, (int) $data['product_id'], (string) $data['serials']);
                    })
                        ? Notification::make()->title(__(':count serial numbers saved', ['count' => $added]))->success()->send()
                        : $action->halt();
                }),
            EditAction::make()->label(__('Edit details'))->icon('heroicon-o-pencil-square')->color('gray'),
        ];
    }
}
