<?php

namespace App\Filament\Resources\PayoutBatches;

use App\Filament\Clusters\Investments;
use App\Filament\Resources\PayoutBatches\Pages\ListPayoutBatches;
use App\Filament\Resources\PayoutBatches\Pages\ViewPayoutBatch;
use App\Filament\Resources\PayoutBatches\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\PayoutBatch;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Investor and channel-partner payouts paid together
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §9, Phase 5). Every step runs
 * through `PayoutBatchService`.
 */
class PayoutBatchResource extends Resource
{
    protected static ?string $model = PayoutBatch::class;

    protected static ?string $cluster = Investments::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('Payout Batches');
    }

    public static function getModelLabel(): string
    {
        return __('payout batch');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Payout Batches');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->hasPermission('investments.payout_batch_create') || $user?->hasPermission('investments.payout_batch_approve')) ?? false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
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

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $count = PayoutBatch::query()->where('status', PayoutBatch::STATUS_DRAFT)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Batch'))->schema([
                TextEntry::make('batch_number')->label(__('Batch'))->formatStateUsing(fn (PayoutBatch $record): string => $record->label()),
                TextEntry::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => PayoutBatch::statusLabel($state))
                    ->color(fn (string $state): string => PayoutBatch::statusColor($state)),
                TextEntry::make('total_amount')->label(__('Total'))->formatStateUsing(fn ($state): string => MoneyFormatter::currency((float) $state)),
                TextEntry::make('item_count')->label(__('Payouts')),
                TextEntry::make('payout_date')->label(__('Payout date'))->date()->placeholder('-'),
                TextEntry::make('fundSource.name')->label(__('Fund source'))->placeholder(__('None — no voucher is booked')),
                TextEntry::make('creator.name')->label(__('Made by'))->placeholder('-'),
                TextEntry::make('created_at')->label(__('Made on'))->dateTime(),
                TextEntry::make('approver.name')->label(__('Approved by'))->placeholder('-'),
                TextEntry::make('approved_at')->label(__('Approved on'))->dateTime()->placeholder('-'),
                TextEntry::make('exported_at')->label(__('Payment list made on'))->dateTime()->placeholder('-'),
                TextEntry::make('export_sha256')->label(__('File SHA-256'))->placeholder('-')->copyable()->extraAttributes(['class' => 'break-all']),
                TextEntry::make('bank_reference')->label(__('Bank reference'))->placeholder('-'),
                TextEntry::make('completer.name')->label(__('Recorded paid by'))->placeholder('-'),
                TextEntry::make('voucher.voucher_number')->label(__('Voucher'))->placeholder('-')
                    ->url(fn (PayoutBatch $record): ?string => $record->voucher_id ? VoucherResource::getUrl('view', ['record' => $record->voucher_id]) : null),
                TextEntry::make('cancel_reason')->label(__('Cancelled because'))->visible(fn (PayoutBatch $record): bool => $record->status === PayoutBatch::STATUS_CANCELLED),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('-')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['creator', 'approver']))
            ->defaultSort('batch_number', 'desc')
            ->columns([
                TextColumn::make('batch_number')->label(__('Batch'))->formatStateUsing(fn (PayoutBatch $record): string => $record->label())->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => PayoutBatch::statusLabel($state))
                    ->color(fn (string $state): string => PayoutBatch::statusColor($state)),
                TextColumn::make('item_count')->label(__('Payouts')),
                TextColumn::make('total_amount')->label(__('Total'))->formatStateUsing(fn ($state): string => MoneyFormatter::currency((float) $state)),
                TextColumn::make('payout_date')->label(__('Payout date'))->date()->placeholder('-')->visibleFrom('md'),
                TextColumn::make('creator.name')->label(__('Made by'))->placeholder('-')->visibleFrom('md'),
                TextColumn::make('approver.name')->label(__('Approved by'))->placeholder('-')->visibleFrom('lg'),
                TextColumn::make('created_at')->label(__('Made on'))->dateTime()->sortable()->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(collect(PayoutBatch::STATUSES)->map(fn (string $label): string => __($label))->all()),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListPayoutBatches::route('/'), 'view' => ViewPayoutBatch::route('/{record}')];
    }
}
