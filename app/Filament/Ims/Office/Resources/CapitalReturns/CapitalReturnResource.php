<?php

namespace App\Filament\Ims\Office\Resources\CapitalReturns;

use App\Filament\Concerns\HasGuardedNavigationBadge;
use App\Filament\Ims\Office\Resources\CapitalReturns\Pages\ListCapitalReturns;
use App\Ims\Capital\CapitalReturnService;
use App\Ims\Capital\MaturityQueue;
use App\Models\CapitalReturn;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Capital returns (IMS-V2/DESIGN.md §8.2): what has matured or matures soon,
 * paying it back, and the history (a return is voided, never deleted).
 */
class CapitalReturnResource extends Resource
{
    use HasGuardedNavigationBadge;

    protected static ?string $model = CapitalReturn::class;

    protected static ?string $slug = 'capital-returns';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('capital return');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Capital returns');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->hasPermission('ims.capital_returns.manage') || $user?->hasPermission('investments.view')) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    protected static function navigationBadge(): ?string
    {
        $count = app(MaturityQueue::class)->dueWithin(0)->count();

        return $count > 0 ? ImsNumbers::count($count) : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->heading(__('Paid back'))
            ->defaultSort('returned_on', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['investor', 'voucher']))
            ->columns([
                TextColumn::make('investor.name')->label(__('Investor'))->searchable()->wrap(),
                TextColumn::make('amount')->label(__('Amount'))->formatStateUsing(fn ($state): string => ImsNumbers::money($state)),
                TextColumn::make('returned_on')->label(__('Paid on'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => $state === CapitalReturn::STATUS_PAID ? __('Paid') : __('Voided'))
                    ->color(fn (string $state): string => $state === CapitalReturn::STATUS_PAID ? 'success' : 'gray')
                    ->description(fn (CapitalReturn $record): ?string => $record->void_reason),
                TextColumn::make('voucher.status')->label(__('Voucher'))->badge()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('proof')
                    ->label(__('Proof'))
                    ->icon('heroicon-o-paper-clip')
                    ->visible(fn (CapitalReturn $record): bool => filled($record->proof_path))
                    ->url(fn (CapitalReturn $record): string => route('filament.office.files.capital-return-proof', $record))
                    ->openUrlInNewTab(),
                Action::make('void')
                    ->label(__('Void'))
                    ->color('danger')
                    ->visible(fn (CapitalReturn $record): bool => $record->status === CapitalReturn::STATUS_PAID && (auth()->user()?->hasPermission('ims.capital_returns.manage') ?? false))
                    ->schema([Textarea::make('reason')->label(__('Why (at least 10 letters)'))->required()->minLength(10)])
                    ->modalDescription(__('The return no longer counts and its voucher is cancelled if Finance has not acted on it yet. The record is kept.'))
                    ->action(function (CapitalReturn $record, array $data): void {
                        app(CapitalReturnService::class)->void($record, (string) $data['reason'], auth()->user());
                        Notification::make()->title(__('Return voided'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('No capital paid back yet'));
    }

    public static function getPages(): array
    {
        return ['index' => ListCapitalReturns::route('/')];
    }
}
