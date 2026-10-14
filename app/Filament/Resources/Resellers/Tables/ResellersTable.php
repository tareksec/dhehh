<?php

namespace App\Filament\Resources\Resellers\Tables;

use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ResellersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withSum('depositEntries as deposit_balance', 'amount'))
            ->columns([
                TextColumn::make('reseller_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => Customer::RESELLER_STATUSES[$state] ?? $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('business_name')->label('Business / shop name')->searchable()->placeholder('-'),
                TextColumn::make('name')->label('Applicant')->searchable(),
                TextColumn::make('phone')->searchable(),
                TextColumn::make('reseller_slug')
                    ->label('Store')
                    ->placeholder('-')
                    ->formatStateUsing(fn (?string $state, Customer $record): ?string => filled($state) && $record->company?->domain
                        ? "{$record->company->domain}/store/{$state}"
                        : $state)
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('deposit_balance')
                    ->label(__('Deposit'))
                    ->state(fn (Customer $record): float => (float) $record->deposit_balance)
                    ->numeric(2)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')->label('Applied')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('reseller_status')
                    ->label('Status')
                    ->options(fn (): array => collect(Customer::RESELLER_STATUSES)->except('none')->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Customer $record): bool => $record->reseller_status !== 'approved')
                    ->action(function (Customer $record): void {
                        $record->ensureResellerSlug();
                        $record->reseller_status = 'approved';
                        $record->save();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Customer $record): bool => $record->reseller_status !== 'rejected')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reseller_note')
                            ->label('Reason')
                            ->rows(2),
                    ])
                    ->fillForm(fn (Customer $record): array => ['reseller_note' => $record->reseller_note])
                    ->action(fn (Customer $record, array $data) => $record->update([
                        'reseller_status' => 'rejected',
                        'reseller_note' => $data['reseller_note'] ?? $record->reseller_note,
                    ])),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approveSelected')
                        ->label('Approve selected')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->action(function (Collection $records): void {
                            $records->each(function (Customer $record): void {
                                $record->ensureResellerSlug();
                                $record->reseller_status = 'approved';
                                $record->save();
                            });

                            Notification::make()->title('Resellers approved')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('rejectSelected')
                        ->label('Reject selected')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records->each->update(['reseller_status' => 'rejected']);

                            Notification::make()->title('Resellers rejected')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
