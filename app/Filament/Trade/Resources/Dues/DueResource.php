<?php

namespace App\Filament\Trade\Resources\Dues;

use App\Filament\Trade\Resources\Dues\Pages\ListDues;
use App\Filament\Trade\TradeErrors;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\OrderPayment;
use App\Services\DeliveryChallanService;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dues (বাকি) in the Trade Portal: every buyer who owes money, most first,
 * and "Receive money" — against one of their challans, or against the old
 * due brought in from the old books. The due is the ERP's customer balance.
 */
class DueResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $slug = 'dues';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static bool $shouldRegisterNavigation = false;

    /** The "against what" choice for money that pays the old due. */
    public const OLD_DUE = 'old';

    public static function getModelLabel(): string
    {
        return __('buyer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Dues');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.dues') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * What a payment from $customer can be put against: each challan with a
     * due, and the old due while the opening balance is not paid off.
     *
     * @return array<string, string>
     */
    public static function againstOptions(Customer $customer): array
    {
        $options = [];
        $challans = DeliveryChallan::query()
            ->with('order')
            ->where('customer_id', $customer->getKey())
            ->where('status', DeliveryChallan::STATUS_CONFIRMED)
            ->orderBy('challan_date')
            ->get()
            ->filter(fn (DeliveryChallan $challan): bool => $challan->dueNow() > 0);

        foreach ($challans as $challan) {
            $options[(string) $challan->getKey()] = __('Challan :number (:date) — due :due', [
                'number' => $challan->challan_number,
                'date' => ImsNumbers::date($challan->challan_date),
                'due' => ImsNumbers::money($challan->dueNow()),
            ]);
        }

        // Whatever the buyer owes beyond their challans: the opening due from the old books (and any ERP order).
        $oldDue = round((float) $customer->current_balance - $challans->sum(fn (DeliveryChallan $challan): float => $challan->dueNow()), 2);

        if ($oldDue > 0) {
            $options[self::OLD_DUE] = __('Old due — :due', ['due' => ImsNumbers::money($oldDue)]);
        }

        return $options;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('current_balance', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->where('current_balance', '>', 0))
            ->columns([
                TextColumn::make('name')->label(__('Buyer'))->searchable()->weight('bold')->wrap(),
                TextColumn::make('phone')->label(__('Phone'))->searchable()->placeholder('—'),
                TextColumn::make('current_balance')->label(__('Due'))->sortable()->color('warning')->formatStateUsing(fn ($state): string => ImsNumbers::money($state)),
            ])
            ->recordActions([
                Action::make('receiveMoney')
                    ->label(__('Receive money'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->schema(fn (Customer $record): array => [
                        Select::make('against')->label(__('For'))->options(static::againstOptions($record))->required()->live(),
                        TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(1)->required(),
                        DatePicker::make('date')->label(__('Date'))->default(now())->maxDate(now())->required(),
                        Select::make('account_id')
                            ->label(__('Where the money went'))
                            ->options(fn (): array => OrderPayment::accountOptions())
                            ->required()
                            ->helperText(fn (): ?string => OrderPayment::accountOptions() === [] ? __('No cash or bank account yet: add one in Menu → Cash and bank accounts.') : null),
                        TextInput::make('note')->label(__('Note'))->placeholder(__('e.g. cheque no. and bank'))->maxLength(255),
                    ])
                    ->action(function (Customer $record, array $data, Action $action): void {
                        $service = app(DeliveryChallanService::class);
                        $account = filled($data['account_id'] ?? null) ? (int) $data['account_id'] : null;

                        $saved = TradeErrors::attempt(function () use ($service, $record, $data, $account): void {
                            if (($data['against'] ?? null) === self::OLD_DUE) {
                                $service->receiveOldDue($record, (float) $data['amount'], $data['date'] ?? null, $account, $data['note'] ?? null);

                                return;
                            }

                            $challan = DeliveryChallan::query()->where('customer_id', $record->getKey())->findOrFail((int) $data['against']);
                            $service->receivePayment($challan, (float) $data['amount'], $data['date'] ?? null, $account, $data['note'] ?? null);
                        });

                        $saved ? Notification::make()->title(__('Money received'))->success()->send() : $action->halt();
                    }),
            ])
            ->emptyStateHeading(__('No customer owes you money.'));
    }

    public static function getPages(): array
    {
        return ['index' => ListDues::route('/')];
    }
}
