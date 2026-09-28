<?php

namespace App\Filament\Resources\ResellerPayoutRequests;

use App\Filament\Clusters\Resellers;
use App\Filament\Resources\ResellerPayoutRequests\Pages\ListResellerPayoutRequests;
use App\Filament\Resources\Resellers\ResellerResource;
use App\Models\ExpenseCategory;
use App\Models\FundSource;
use App\Models\ResellerPayoutRequest;
use App\Models\Voucher;
use App\Services\ResellerPayoutService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Reseller withdrawal requests (owner's rule: the reseller requests, staff
 * approve and pay). requested -> approved -> paid, or -> rejected, which
 * releases the amount back to the reseller's available balance.
 */
class ResellerPayoutRequestResource extends Resource
{
    protected static ?string $model = ResellerPayoutRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $cluster = Resellers::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'reseller-payout-requests';

    public static function getNavigationLabel(): string
    {
        return __('Withdrawal requests');
    }

    public static function getModelLabel(): string
    {
        return __('Withdrawal request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Withdrawal requests');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $count = ResellerPayoutRequest::query()->where('status', ResellerPayoutRequest::STATUS_REQUESTED)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('Requested'))->dateTime()->sortable(),
                TextColumn::make('reseller.business_name')
                    ->label(__('Reseller'))
                    ->formatStateUsing(fn ($state, ResellerPayoutRequest $record): ?string => $state ?: $record->reseller?->name)
                    ->description(fn (ResellerPayoutRequest $record): ?string => $record->reseller?->phone)
                    ->searchable(),
                TextColumn::make('amount')->label(__('Amount'))->numeric(2)->sortable(),
                TextColumn::make('method')
                    ->label(__('Payment method'))
                    ->formatStateUsing(fn (?string $state): string => __(ResellerPayoutRequest::METHODS[$state] ?? (string) $state)),
                TextColumn::make('account_details')->label(__('Account number / details'))->copyable()->wrap(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => __(ResellerPayoutRequest::STATUSES[$state] ?? (string) $state))
                    ->color(fn (?string $state): string => match ($state) {
                        ResellerPayoutRequest::STATUS_PAID => 'success',
                        ResellerPayoutRequest::STATUS_APPROVED => 'info',
                        ResellerPayoutRequest::STATUS_REQUESTED => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('payment_reference')->label(__('Reference'))->placeholder('-')->toggleable(),
                TextColumn::make('paid_at')->label(__('Paid at'))->dateTime()->placeholder('-')->toggleable(),
                TextColumn::make('voucher.voucher_number')->label(__('Voucher'))->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(fn (): array => collect(ResellerPayoutRequest::STATUSES)->map(fn (string $label): string => __($label))->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (ResellerPayoutRequest $record): bool => $record->status === ResellerPayoutRequest::STATUS_REQUESTED && static::canManage())
                    ->action(fn (ResellerPayoutRequest $record) => $record->update([
                        'status' => ResellerPayoutRequest::STATUS_APPROVED,
                        'approved_at' => now(),
                        'processed_by' => Auth::id(),
                    ])),
                Action::make('markPaid')
                    ->label(__('Mark paid'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('success')
                    ->visible(fn (ResellerPayoutRequest $record): bool => in_array($record->status, [ResellerPayoutRequest::STATUS_REQUESTED, ResellerPayoutRequest::STATUS_APPROVED], true) && static::canManage())
                    ->modalDescription(__('A pending debit voucher is created in Finance for this amount. It is booked as an expense once approved in Vouchers.'))
                    ->schema([
                        TextInput::make('payment_reference')
                            ->label(__('Payment reference (transaction ID)'))
                            ->required()
                            ->maxLength(255),
                        Select::make('fund_source_id')
                            ->label(__('Paid from (fund source)'))
                            ->options(fn (): array => FundSource::query()->where('is_active', true)->whereNotNull('account_id')->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (): ?int => static::lastPaidVoucher()?->fund_source_id)
                            ->searchable()
                            ->required(),
                        Select::make('expense_category_id')
                            ->label(__('Expense category'))
                            ->options(fn (): array => ExpenseCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (): ?int => static::lastPaidVoucher()?->expense_category_id)
                            ->searchable()
                            ->required(),
                        Textarea::make('admin_note')->label(__('Note'))->rows(2),
                    ])
                    ->action(function (ResellerPayoutRequest $record, array $data): void {
                        $voucher = app(ResellerPayoutService::class)->markPaid($record, $data, Auth::user());

                        Notification::make()
                            ->title(__('Marked paid. Voucher :number is waiting for approval in Finance.', ['number' => $voucher->voucher_number]))
                            ->success()
                            ->send();
                    }),
                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ResellerPayoutRequest $record): bool => in_array($record->status, [ResellerPayoutRequest::STATUS_REQUESTED, ResellerPayoutRequest::STATUS_APPROVED], true) && static::canManage())
                    ->schema([
                        Textarea::make('admin_note')->label(__('Reason'))->required()->rows(2),
                    ])
                    ->action(fn (ResellerPayoutRequest $record, array $data) => $record->update([
                        'status' => ResellerPayoutRequest::STATUS_REJECTED,
                        'admin_note' => $data['admin_note'],
                        'processed_by' => Auth::id(),
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResellerPayoutRequests::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (Auth::user()?->canPerformModelAbility('viewAny', ResellerPayoutRequest::class) ?? false)
            && ResellerResource::resellerModuleEnabledForCurrentContext();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Pre-fills the fund source/category with whatever was used last time. */
    protected static function lastPaidVoucher(): ?Voucher
    {
        return ResellerPayoutRequest::query()
            ->whereNotNull('voucher_id')
            ->latest('paid_at')
            ->first()
            ?->voucher;
    }

    protected static function canManage(): bool
    {
        return Auth::user()?->canPerformModelAbility('update', ResellerPayoutRequest::class) ?? false;
    }
}
