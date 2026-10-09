<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\Concerns\ForApprovedResellers;
use App\Models\Customer;
use App\Models\ResellerPayoutAccount;
use App\Models\ResellerPayoutRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reseller portal Phase 5 (13_RESELLER_PORTAL_PLAN.md §3 row 5): payout
 * accounts (bKash / Nagad / Rocket numbers, bank details) saved once and
 * picked from a list on the Withdrawals form. Every query is scoped to the
 * logged-in reseller, so another reseller's account can't be opened, edited
 * or deleted by id.
 */
class PayoutAccounts extends Page implements HasTable
{
    use ForApprovedResellers;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.reseller.pages.payout-accounts';

    public function getTitle(): string
    {
        return __('Saved payout accounts');
    }

    public static function getNavigationLabel(): string
    {
        return __('Saved payout accounts');
    }

    /**
     * Saves an account for the reseller (a repeat of the same method +
     * details updates that row instead of duplicating it). The reseller's
     * first account becomes their default automatically; asking for a
     * default moves the flag off every other account.
     */
    public static function store(Customer $reseller, string $method, string $details, ?string $label = null, bool $makeDefault = false): ResellerPayoutAccount
    {
        return DB::transaction(function () use ($reseller, $method, $details, $label, $makeDefault): ResellerPayoutAccount {
            $isFirst = ! $reseller->resellerPayoutAccounts()->exists();

            $account = $reseller->resellerPayoutAccounts()->firstOrNew(['method' => $method, 'account_details' => $details]);
            $account->company_id = $reseller->company_id;

            if (filled($label)) {
                $account->label = $label;
            }

            $account->save();

            if ($isFirst || $makeDefault) {
                static::makeDefault($account);
            }

            return $account->refresh();
        });
    }

    /** Used by the Withdrawals form's "save this account" toggle. */
    public static function remember(Customer $reseller, string $method, string $details): ResellerPayoutAccount
    {
        return static::store($reseller, $method, $details);
    }

    public static function makeDefault(ResellerPayoutAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            ResellerPayoutAccount::query()
                ->where('customer_id', $account->customer_id)
                ->whereKeyNot($account->getKey())
                ->update(['is_default' => false]);

            $account->forceFill(['is_default' => true])->save();
        });
    }

    /** @return array<int, Component> */
    protected function accountFields(): array
    {
        return [
            Select::make('method')
                ->label(__('Payment method'))
                ->options(collect(ResellerPayoutRequest::METHODS)->map(fn (string $label): string => __($label))->all())
                ->required(),

            TextInput::make('account_details')
                ->label(__('Account number / details'))
                ->required()
                ->maxLength(500),

            TextInput::make('label')
                ->label(__('Label (optional)'))
                ->maxLength(100),

            Toggle::make('is_default')
                ->label(__('Use as my default account')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label(__('Add account'))
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading(__('Add a payout account'))
                ->modalWidth('lg')
                ->schema($this->accountFields())
                ->action(function (array $data): void {
                    static::store(
                        $this->reseller(),
                        $data['method'],
                        $data['account_details'],
                        $data['label'] ?? null,
                        (bool) ($data['is_default'] ?? false),
                    );

                    Notification::make()->title(__('Payout account saved.'))->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ResellerPayoutAccount::query()
                ->where('customer_id', $this->reseller()->getKey()))
            ->columns([
                TextColumn::make('method')
                    ->label(__('Payment method'))
                    ->formatStateUsing(fn (string $state): string => __(ResellerPayoutRequest::METHODS[$state] ?? $state)),

                TextColumn::make('account_details')
                    ->label(__('Account number / details'))
                    ->wrap(),

                TextColumn::make('label')
                    ->label(__('Label'))
                    ->placeholder('—'),

                IconColumn::make('is_default')
                    ->label(__('Default'))
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('makeDefault')
                    ->label(__('Make default'))
                    ->icon(Heroicon::OutlinedStar)
                    ->visible(fn (ResellerPayoutAccount $record): bool => ! $record->is_default)
                    ->action(function (ResellerPayoutAccount $record): void {
                        static::makeDefault($record);

                        Notification::make()->title(__('Default account updated.'))->success()->send();
                    }),

                EditAction::make()
                    ->modalWidth('lg')
                    ->schema($this->accountFields())
                    ->using(function (ResellerPayoutAccount $record, array $data): ResellerPayoutAccount {
                        $record->update([
                            'method' => $data['method'],
                            'account_details' => $data['account_details'],
                            'label' => $data['label'] ?? null,
                        ]);

                        if ($data['is_default'] ?? false) {
                            static::makeDefault($record);
                        } else {
                            $record->forceFill(['is_default' => false])->save();
                        }

                        return $record;
                    }),

                DeleteAction::make(),
            ])
            ->defaultSort('id');
    }
}
