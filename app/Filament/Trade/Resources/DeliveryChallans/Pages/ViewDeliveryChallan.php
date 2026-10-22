<?php

namespace App\Filament\Trade\Resources\DeliveryChallans\Pages;

use App\Filament\Trade\Resources\DeliveryChallans\DeliveryChallanResource;
use App\Filament\Trade\TradeErrors;
use App\Models\DeliveryChallan;
use App\Models\OrderPayment;
use App\Services\DeliveryChallanService;
use App\Support\ImsNumbers;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * One challan: the paper as written, the money received so far and the due,
 * with Print, Receive money and Cancel.
 */
class ViewDeliveryChallan extends ViewRecord
{
    protected static string $resource = DeliveryChallanResource::class;

    protected string $view = 'filament.trade.challans.view';

    public function getTitle(): string
    {
        return __('Challan :number', ['number' => $this->getRecord()->challan_number]);
    }

    public function getRecord(): DeliveryChallan
    {
        return parent::getRecord();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label(__('Print'))
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('filament.trade.challans.print', $this->getRecord()))
                ->openUrlInNewTab(),
            static::receiveMoneyAction(),
            Action::make('cancel')
                ->label(__('Cancel challan'))
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->visible(fn (): bool => ! $this->getRecord()->isCancelled())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => __('The goods go back to stock and the due is removed. Money already received (:paid) stays recorded; give it back by hand if needed.', ['paid' => ImsNumbers::money($this->getRecord()->paidSoFar())]))
                ->schema([Textarea::make('reason')->label(__('Why'))->required()->minLength(5)])
                ->action(function (array $data, Action $action): void {
                    TradeErrors::attempt(fn () => app(DeliveryChallanService::class)->cancel($this->getRecord(), (string) $data['reason'], auth()->user()))
                        ? Notification::make()->title(__('Challan cancelled'))->success()->send()
                        : $action->halt();
                }),
        ];
    }

    public static function receiveMoneyAction(): Action
    {
        return Action::make('receiveMoney')
            ->label(__('Receive money'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (ViewDeliveryChallan $livewire): bool => $livewire->getRecord()->dueNow() > 0)
            ->modalDescription(fn (ViewDeliveryChallan $livewire): string => __('Due now: :due', ['due' => ImsNumbers::money($livewire->getRecord()->dueNow())]))
            ->schema([
                TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(1)->required(),
                DatePicker::make('date')->label(__('Date'))->default(now())->maxDate(now())->required(),
                Select::make('account_id')->label(__('Where the money went'))->options(fn (): array => OrderPayment::accountOptions())->required(),
                TextInput::make('note')->label(__('Note'))->placeholder(__('e.g. cheque no. and bank'))->maxLength(255),
            ])
            ->action(function (array $data, ViewDeliveryChallan $livewire, Action $action): void {
                TradeErrors::attempt(fn () => app(DeliveryChallanService::class)->receivePayment(
                    $livewire->getRecord(),
                    (float) $data['amount'],
                    $data['date'] ?? null,
                    filled($data['account_id'] ?? null) ? (int) $data['account_id'] : null,
                    $data['note'] ?? null,
                ))
                    ? Notification::make()->title(__('Money received'))->success()->send()
                    : $action->halt();
            });
    }
}
