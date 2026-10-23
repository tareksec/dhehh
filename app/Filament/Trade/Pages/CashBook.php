<?php

namespace App\Filament\Trade\Pages;

use App\Filament\Trade\Resources;
use App\Filament\Trade\Resources\Dues\DueResource;
use App\Filament\Trade\TradeErrors;
use App\Models\ExpenseCategory;
use App\Models\OrderPayment;
use App\Models\Supplier;
use App\Services\TradeCashBookService;
use App\Support\ImsNumbers;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * The Trade Portal cash book (docs/tasneem-samples/PLAN.md §5): one day at a
 * time (money at the start, every line, money in and out, each account at the
 * end) or a whole month, read by TradeCashBookService from the ERP's account
 * ledger. The buttons record what the book is missing: a bank deposit or any
 * move between accounts, a supplier payment and a cost. Money from buyers
 * comes in through challans and Dues.
 */
class CashBook extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'cash-book';

    protected string $view = 'filament.trade.pages.cash-book';

    /** "day" or "month". */
    public string $mode = 'day';

    /** The day shown (Y-m-d); for the month view, any day of the month. */
    public string $date = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.cashbook') ?? false;
    }

    public function getTitle(): string
    {
        return __('Cash book');
    }

    public static function getNavigationLabel(): string
    {
        return __('Cash book');
    }

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function showMode(string $mode): void
    {
        $this->mode = $mode === 'month' ? 'month' : 'day';
    }

    /** From the month's day list to that day's book. */
    public function openDay(string $date): void
    {
        $this->date = $date;
        $this->mode = 'day';
    }

    /** One day or one month back (-1) or forward (+1); never past today. */
    public function step(int $direction): void
    {
        $current = $this->day();
        $moved = $this->mode === 'month' ? $current->addMonthsNoOverflow($direction) : $current->addDays($direction);

        $this->date = ($moved->greaterThan(CarbonImmutable::today()) ? CarbonImmutable::today() : $moved)->toDateString();
    }

    public function day(): CarbonImmutable
    {
        try {
            $day = CarbonImmutable::parse($this->date ?: 'today')->startOfDay();
        } catch (\Throwable) {
            $day = CarbonImmutable::today();
        }

        return $day->greaterThan(CarbonImmutable::today()) ? CarbonImmutable::today() : $day;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $service = app(TradeCashBookService::class);

        return [
            'book' => $this->mode === 'day' ? $service->day($this->day()) : null,
            'month' => $this->mode === 'month' ? $service->month($this->day()) : null,
            'hasAccounts' => $service->accounts()->isNotEmpty(),
            'atToday' => $this->mode === 'month' ? $this->day()->isSameMonth(CarbonImmutable::today()) : $this->day()->isToday(),
            'accountsUrl' => Resources\MoneyAccounts\MoneyAccountResource::canAccess() ? Resources\MoneyAccounts\MoneyAccountResource::getUrl(panel: 'trade') : null,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('moveMoney')
                ->label(__('Bank deposit / move money'))
                ->icon('heroicon-o-arrows-right-left')
                ->schema([
                    Select::make('from_account_id')->label(__('From'))->options(fn (): array => OrderPayment::accountOptions())->required(),
                    Select::make('to_account_id')->label(__('To'))->options(fn (): array => OrderPayment::accountOptions())->required()->different('from_account_id'),
                    TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(1)->required(),
                    DatePicker::make('date')->label(__('Date'))->default(now())->maxDate(now())->required(),
                    TextInput::make('note')->label(__('Note'))->placeholder(__('e.g. deposit slip no.'))->maxLength(255),
                ])
                ->action(function (array $data, Action $action): void {
                    TradeErrors::attempt(fn () => app(TradeCashBookService::class)->moveMoney((int) $data['from_account_id'], (int) $data['to_account_id'], (float) $data['amount'], (string) $data['date'], $data['note'] ?? null, auth()->user()))
                        ? Notification::make()->title(__('Money moved'))->success()->send()
                        : $action->halt();
                }),
            Action::make('paySupplier')
                ->label(__('Pay a supplier'))
                ->icon('heroicon-o-truck')
                ->color('gray')
                ->schema([
                    Select::make('supplier_id')
                        ->label(__('Supplier'))
                        ->options(fn (): array => Supplier::query()->where('current_balance', '>', 0)->orderBy('name')->get()
                            ->mapWithKeys(fn (Supplier $supplier): array => [$supplier->getKey() => __(':name — owed :due', ['name' => $supplier->name, 'due' => ImsNumbers::money($supplier->current_balance)])])
                            ->all())
                        ->searchable()
                        ->required(),
                    TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(1)->required(),
                    DatePicker::make('date')->label(__('Date'))->default(now())->maxDate(now())->required(),
                    Select::make('account_id')->label(__('Paid from'))->options(fn (): array => OrderPayment::accountOptions())->required(),
                    TextInput::make('note')->label(__('Note'))->placeholder(__('e.g. cheque no. and bank'))->maxLength(255),
                ])
                ->action(function (array $data, Action $action): void {
                    TradeErrors::attempt(fn () => app(TradeCashBookService::class)->paySupplier((int) $data['supplier_id'], (float) $data['amount'], (string) $data['date'], (int) $data['account_id'], $data['note'] ?? null))
                        ? Notification::make()->title(__('Supplier payment recorded'))->success()->send()
                        : $action->halt();
                }),
            Action::make('recordCost')
                ->label(__('Other cost'))
                ->icon('heroicon-o-receipt-percent')
                ->color('gray')
                ->schema([
                    Select::make('expense_category_id')
                        ->label(__('What for'))
                        ->options(fn (): array => ExpenseCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->live()
                        ->helperText(__('Not in the list? Leave it empty and write the name below.')),
                    TextInput::make('new_category')
                        ->label(__('New cost name'))
                        ->placeholder(__('e.g. Truck fare, Loading, Shop rent'))
                        ->required(fn (Get $get): bool => blank($get('expense_category_id')))
                        ->visible(fn (Get $get): bool => blank($get('expense_category_id')))
                        ->maxLength(255),
                    TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(1)->required(),
                    DatePicker::make('date')->label(__('Date'))->default(now())->maxDate(now())->required(),
                    Select::make('account_id')->label(__('Paid from'))->options(fn (): array => OrderPayment::accountOptions())->required(),
                    TextInput::make('note')->label(__('Note'))->maxLength(255),
                ])
                ->action(function (array $data, Action $action): void {
                    TradeErrors::attempt(fn () => app(TradeCashBookService::class)->recordCost(
                        filled($data['expense_category_id'] ?? null) ? (int) $data['expense_category_id'] : null,
                        $data['new_category'] ?? null,
                        (float) $data['amount'],
                        (string) $data['date'],
                        (int) $data['account_id'],
                        $data['note'] ?? null,
                    ))
                        ? Notification::make()->title(__('Cost recorded'))->success()->send()
                        : $action->halt();
                }),
            Action::make('receiveMoney')
                ->label(__('Receive money'))
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (): bool => DueResource::canAccess())
                ->url(fn (): string => DueResource::getUrl(panel: 'trade')),
        ];
    }
}
