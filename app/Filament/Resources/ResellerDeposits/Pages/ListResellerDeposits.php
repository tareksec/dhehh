<?php

namespace App\Filament\Resources\ResellerDeposits\Pages;

use App\Filament\Resources\ResellerDeposits\ResellerDepositResource;
use App\Models\Customer;
use App\Models\ResellerDepositEntry;
use App\Services\ResellerDepositService;
use App\Services\ResellerOwnCourierService;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ListResellerDeposits extends ListRecords
{
    protected static string $resource = ResellerDepositResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->adjustmentAction(ResellerDepositService::ADD),
            $this->adjustmentAction(ResellerDepositService::DEDUCT),
        ];
    }

    protected function adjustmentAction(string $direction): Action
    {
        $adding = $direction === ResellerDepositService::ADD;

        return Action::make($adding ? 'addToDeposit' : 'deductFromDeposit')
            ->label($adding ? __('Add to deposit') : __('Deduct from deposit'))
            ->icon($adding ? Heroicon::OutlinedPlusCircle : Heroicon::OutlinedMinusCircle)
            ->color($adding ? 'success' : 'danger')
            ->modalHeading($adding ? __('Add to a reseller\'s deposit') : __('Deduct from a reseller\'s deposit'))
            ->modalDescription(__('The reason is required. The change is kept in the deposit list and in the audit log.'))
            ->visible(fn (): bool => static::canManage())
            ->schema([
                Select::make('customer_id')
                    ->label(__('Reseller'))
                    ->options(fn (): array => Customer::query()
                        ->where('reseller_status', 'approved')
                        ->orderBy('business_name')
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Customer $reseller): array => [
                            $reseller->getKey() => ($reseller->business_name ?: $reseller->name).' — '.$reseller->phone,
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->live(),
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->numeric()
                    ->minValue(0.01)
                    ->required()
                    ->helperText(fn ($get): ?string => $get('customer_id') && ($reseller = Customer::query()->find($get('customer_id')))
                        ? __('Deposit now: :balance', ['balance' => MoneyFormatter::number(app(ResellerOwnCourierService::class)->depositBalance($reseller))])
                        : null),
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->maxLength(450)
                    ->rows(3),
            ])
            ->action(function (array $data) use ($direction): void {
                $reseller = Customer::query()->where('reseller_status', 'approved')->findOrFail($data['customer_id']);

                try {
                    app(ResellerDepositService::class)->adjust($reseller, $direction, (float) $data['amount'], (string) $data['reason'], Auth::user());
                } catch (ValidationException $exception) {
                    Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('Deposit updated. It now holds :balance.', ['balance' => MoneyFormatter::number(app(ResellerOwnCourierService::class)->depositBalance($reseller))]))
                    ->success()
                    ->send();
            });
    }

    protected static function canManage(): bool
    {
        return Auth::user()?->canPerformModelAbility('update', ResellerDepositEntry::class) ?? false;
    }
}
