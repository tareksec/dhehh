<?php

namespace App\Filament\Resources\InvestmentProjects\RelationManagers;

use App\Ims\Profit\ProjectFinancials;
use App\Models\Purchase;
use App\Support\ImsNumbers;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * ERP purchases whose goods this project paid for (IMS-V2/PRD.md FR-44 a):
 * the system then works out the project's sales, costs and stock by itself
 * (App\Ims\Profit\ProjectFinancials). Frozen once every payout is paid.
 */
class LinkedPurchasesRelationManager extends RelationManager
{
    protected static string $relationship = 'linkedPurchases';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Linked purchases');
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->hasPermission('investments.manage') ?? false) || $this->getOwnerRecord()->isFrozen();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('purchase_number')
            ->columns([
                TextColumn::make('purchase_number')->label(__('Purchase'))->searchable(),
                TextColumn::make('purchase_date')->label(__('Date'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
                TextColumn::make('landed')->label(__('Landed cost'))->state(fn (Purchase $record): string => ImsNumbers::money($record->landedCostTotal())),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label(__('Link a purchase'))
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['purchase_number'])
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'company_id' => $this->getOwnerRecord()->company_id]),
                Action::make('figures')
                    ->label(__('See the worked-out figures'))
                    ->color('gray')
                    ->icon('heroicon-o-calculator')
                    ->modalSubmitAction(false)
                    ->modalContent(function (): HtmlString {
                        $f = app(ProjectFinancials::class)->compute($this->getOwnerRecord());
                        $rows = [
                            __('Goods bought') => ImsNumbers::money($f['costs']['goods']),
                            __('Shipping, customs and import') => ImsNumbers::money($f['costs']['import']),
                            __('Other landed costs') => ImsNumbers::money($f['costs']['landed_items']),
                            __('Local expenses') => ImsNumbers::money($f['costs']['local_items']),
                            __('Total cost') => ImsNumbers::money($f['costs']['total']),
                            __('Units sold') => ImsNumbers::count($f['units_sold']),
                            __('Units in stock') => ImsNumbers::count($f['units_in_stock']),
                            __('Sales') => ImsNumbers::money($f['revenue']),
                            __('Net profit') => ImsNumbers::money($f['net_profit'], signed: true),
                        ];
                        $html = '<dl class="grid grid-cols-2 gap-2 text-sm">';

                        foreach ($rows as $label => $value) {
                            $html .= '<dt>'.e($label).'</dt><dd class="text-end font-semibold">'.e($value).'</dd>';
                        }

                        $html .= '</dl>';

                        if ($f['shared_products'] > 0) {
                            $html .= '<p class="mt-3 text-sm text-warning-600">'.e(__(':count products are also in another project\'s purchases, so their sales are not counted here. Enter that profit by hand when settling.', ['count' => $f['shared_products']])).'</p>';
                        }

                        return new HtmlString($html);
                    }),
            ])
            ->recordActions([
                DetachAction::make()->label(__('Unlink'))
                    ->after(fn () => Notification::make()->title(__('Purchase unlinked'))->success()->send()),
            ])
            ->emptyStateHeading(__('No purchase linked'))
            ->emptyStateDescription(__('Link the ERP purchases this project paid for: their sales and costs are then worked out by themselves.'));
    }
}
