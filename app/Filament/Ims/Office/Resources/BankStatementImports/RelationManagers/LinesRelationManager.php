<?php

namespace App\Filament\Ims\Office\Resources\BankStatementImports\RelationManagers;

use App\Ims\Investments\MoneyConfirmationService;
use App\Ims\Reconciliation\Matcher;
use App\Models\BankStatementLine;
use App\Models\Investment;
use App\Models\InvestorGroupMember;
use App\Support\ImsNumbers;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The money-in lines of one statement. The accountant marks which waiting
 * money a line pays for (same amount only); someone with
 * `ims.investments.confirm` then confirms it (IMS-V2/ROLES.md §3.3).
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Money-in lines');
    }

    private static function describe(Investment|InvestorGroupMember|null $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $place = $record instanceof InvestorGroupMember ? $record->group?->name : $record->project?->name;

        return trim(($record->investor?->name ?? '').' — '.ImsNumbers::money($record->amount).' — '.($place ?? '').($record->payment_reference ? ' ('.$record->payment_reference.')' : ''));
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('transacted_on')->label(__('Date'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
                TextColumn::make('amount')->label(__('Amount'))->formatStateUsing(fn ($state): string => ImsNumbers::money($state)),
                TextColumn::make('reference')->label(__('Transaction id'))->placeholder('—'),
                TextColumn::make('counterparty')->label(__('From'))->placeholder('—')->wrap(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => BankStatementLine::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        BankStatementLine::STATUS_MATCHED => 'success',
                        BankStatementLine::STATUS_SUGGESTED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('match')->label(__('Pays for'))->state(fn (BankStatementLine $record): ?string => static::describe($record->matched))->placeholder('—')->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(collect(BankStatementLine::STATUSES)->map(fn (string $label): string => __($label))->all()),
            ])
            ->recordActions([
                Action::make('match')
                    ->label(__('Mark the match'))
                    ->icon('heroicon-o-link')
                    ->visible(fn (BankStatementLine $record): bool => in_array($record->status, [BankStatementLine::STATUS_UNMATCHED, BankStatementLine::STATUS_SUGGESTED], true) && (auth()->user()?->hasPermission('ims.reconcile') ?? false))
                    ->fillForm(fn (BankStatementLine $record): array => ['target' => $record->matched_id ? ($record->matched_type === InvestorGroupMember::class ? 'm:' : 'i:').$record->matched_id : null])
                    ->schema(fn (BankStatementLine $record): array => [
                        Select::make('target')->label(__('Waiting money of the same amount'))
                            ->options(app(Matcher::class)->candidates($record)->mapWithKeys(fn ($candidate): array => [($candidate instanceof InvestorGroupMember ? 'm:' : 'i:').$candidate->getKey() => (string) static::describe($candidate)])->all())
                            ->required(),
                    ])
                    ->action(function (BankStatementLine $record, array $data): void {
                        [$kind, $id] = explode(':', (string) $data['target'], 2);
                        $target = $kind === 'm' ? InvestorGroupMember::query()->findOrFail($id) : Investment::query()->findOrFail($id);
                        app(Matcher::class)->markMatched($record, $target, auth()->user());
                        Notification::make()->title(__('Matched. Someone who confirms money can now make it active.'))->success()->send();
                    }),
                Action::make('confirm')
                    ->label(__('Money received'))
                    ->color('success')
                    ->icon('heroicon-o-check')
                    ->visible(fn (BankStatementLine $record): bool => $record->status === BankStatementLine::STATUS_MATCHED && $record->matched?->isPending() && (auth()->user()?->hasPermission('ims.investments.confirm') ?? false))
                    ->requiresConfirmation()
                    ->action(function (BankStatementLine $record): void {
                        app(MoneyConfirmationService::class)->confirm($record->matched, auth()->user());
                        Notification::make()->title(__('Money confirmed'))->success()->send();
                    }),
                Action::make('ignore')
                    ->label(__('Ignore'))
                    ->color('gray')
                    ->visible(fn (BankStatementLine $record): bool => $record->status !== BankStatementLine::STATUS_MATCHED && $record->status !== BankStatementLine::STATUS_IGNORED && (auth()->user()?->hasPermission('ims.reconcile') ?? false))
                    ->action(fn (BankStatementLine $record) => app(Matcher::class)->ignore($record, auth()->user())),
            ]);
    }
}
