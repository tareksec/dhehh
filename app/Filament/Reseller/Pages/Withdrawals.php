<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\Concerns\ForApprovedResellers;
use App\Models\ResellerPayoutAccount;
use App\Models\ResellerPayoutRequest;
use App\Services\ResellerCommissionService;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reseller portal Phase 5 (13_RESELLER_PORTAL_PLAN.md §3 row 5): balance
 * cards, the withdrawal request form (the store's minimum and the reseller's
 * available balance enforced, exactly as the old storefront page does), and
 * the full request history with status and transaction reference. The form
 * can fill itself from a saved payout account (see PayoutAccounts).
 */
class Withdrawals extends Page implements HasTable
{
    use ForApprovedResellers;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.reseller.pages.withdrawals';

    public function getTitle(): string
    {
        return __('Withdrawals');
    }

    public static function getNavigationLabel(): string
    {
        return __('Withdrawals');
    }

    /** @return array{pending: float, earned: float, withdrawn: float, in_process: float, available: float} */
    public function balances(): array
    {
        return app(ResellerCommissionService::class)->balances($this->reseller());
    }

    public function minimumWithdrawal(): float
    {
        return (float) ($this->company()?->storefrontSetting?->reseller_min_withdrawal ?? 0);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('managePayoutAccounts')
                ->label(__('Saved payout accounts'))
                ->icon(Heroicon::OutlinedCreditCard)
                ->color('gray')
                ->url(fn (): string => PayoutAccounts::getUrl()),

            $this->requestWithdrawalAction(),
        ];
    }

    protected function requestWithdrawalAction(): Action
    {
        $currency = $this->currency();
        $available = $this->balances()['available'];
        $minimum = max(0.01, $this->minimumWithdrawal());

        return Action::make('requestWithdrawal')
            ->label(__('Request withdrawal'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->modalHeading(__('Request a withdrawal'))
            ->modalWidth('lg')
            ->disabled($available < $minimum)
            ->tooltip($available < $minimum
                ? __('Your available balance is below the minimum withdrawal of :amount.', ['amount' => MoneyFormatter::currency($minimum, $currency)])
                : null)
            ->fillForm(function (): array {
                $default = $this->reseller()->resellerPayoutAccounts()->where('is_default', true)->first();

                return [
                    'saved_account_id' => $default?->getKey(),
                    'method' => $default?->method,
                    'account_details' => $default?->account_details,
                ];
            })
            ->schema([
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->numeric()
                    ->required()
                    ->prefix($currency)
                    ->minValue($minimum)
                    ->maxValue($available)
                    ->helperText(__('Available to withdraw').': '.MoneyFormatter::currency($available, $currency))
                    ->validationMessages([
                        'min' => __('The minimum withdrawal is :amount.', ['amount' => MoneyFormatter::number($minimum)]),
                        'max' => __('The amount is more than your available balance.'),
                    ]),

                Select::make('saved_account_id')
                    ->label(__('Saved payout accounts'))
                    ->options(fn (): array => $this->reseller()->resellerPayoutAccounts()->get()
                        ->mapWithKeys(fn (ResellerPayoutAccount $account): array => [$account->getKey() => $account->displayName()])
                        ->all())
                    ->placeholder(__('Enter a new account'))
                    ->visible(fn (): bool => $this->reseller()->resellerPayoutAccounts()->exists())
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $account = $state ? $this->reseller()->resellerPayoutAccounts()->find($state) : null;

                        if ($account) {
                            $set('method', $account->method);
                            $set('account_details', $account->account_details);
                        }
                    }),

                Select::make('method')
                    ->label(__('Payment method'))
                    ->options(collect(ResellerPayoutRequest::METHODS)->map(fn (string $label): string => __($label))->all())
                    ->required(),

                TextInput::make('account_details')
                    ->label(__('Account number / details'))
                    ->required()
                    ->maxLength(500),

                Toggle::make('save_account')
                    ->label(__('Save this account for next time'))
                    ->visible(fn (Get $get): bool => blank($get('saved_account_id'))),
            ])
            ->action(function (array $data): void {
                $reseller = $this->reseller();

                $created = app(ResellerCommissionService::class)->requestPayout(
                    $reseller,
                    (float) $data['amount'],
                    $data['method'],
                    $data['account_details'],
                );

                if (! $created) {
                    Notification::make()
                        ->title(__('The amount is more than your available balance.'))
                        ->danger()
                        ->send();

                    return;
                }

                if (! empty($data['save_account'])) {
                    PayoutAccounts::remember($reseller, $data['method'], $data['account_details']);
                }

                Notification::make()
                    ->title(__('Your withdrawal request has been sent. The store will review it.'))
                    ->success()
                    ->send();
            });
    }

    public function table(Table $table): Table
    {
        $currency = $this->currency();

        return $table
            ->query(fn (): Builder => ResellerPayoutRequest::query()
                ->where('customer_id', $this->reseller()->getKey()))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->state(fn (ResellerPayoutRequest $record): string => MoneyFormatter::currency($record->amount, $currency))
                    ->sortable(),

                TextColumn::make('method')
                    ->label(__('Payment method'))
                    ->formatStateUsing(fn (string $state): string => __(ResellerPayoutRequest::METHODS[$state] ?? $state)),

                TextColumn::make('account_details')
                    ->label(__('Account number / details'))
                    ->limit(30)
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __(ResellerPayoutRequest::STATUSES[$state] ?? $state))
                    ->color(fn (string $state): string => match ($state) {
                        ResellerPayoutRequest::STATUS_PAID => 'success',
                        ResellerPayoutRequest::STATUS_REJECTED => 'danger',
                        ResellerPayoutRequest::STATUS_APPROVED => 'info',
                        default => 'warning',
                    }),

                // Same rule as the storefront page: the transaction reference
                // once paid, or the store's note when a request was rejected.
                // Never the admin note on any other status (internal).
                TextColumn::make('reference')
                    ->label(__('Reference'))
                    ->state(fn (ResellerPayoutRequest $record): ?string => $record->payment_reference
                        ?: ($record->status === ResellerPayoutRequest::STATUS_REJECTED ? $record->admin_note : null))
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(collect(ResellerPayoutRequest::STATUSES)->map(fn (string $label): string => __($label))->all()),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
