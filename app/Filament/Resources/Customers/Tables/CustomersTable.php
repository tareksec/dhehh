<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Models\Customer;
use App\Models\CustomerRiskProfile;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('address')
                    ->label('Customer Address')
                    ->searchable()
                    ->wrap()
                    ->lineClamp(3)
                    ->extraAttributes(['style' => 'min-width: 16rem'])
                    ->placeholder('-'),

                TextColumn::make('email')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('customer_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => Customer::typeLabel($state))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('customer_source')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => Customer::sourceLabel($state))
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('current_balance')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->sortable(),

                TextColumn::make('riskProfile.risk_level')
                    ->label('Risk')
                    ->badge()
                    ->placeholder('Not checked')
                    ->formatStateUsing(fn (?string $state): string => CustomerRiskProfile::LEVELS[$state ?? ''] ?? 'Not checked')
                    ->color(fn (?string $state): string => match ($state) {
                        'low' => 'success', 'medium' => 'warning', 'high', 'blacklisted' => 'danger', default => 'gray',
                    }),

                TextColumn::make('riskProfile.risk_score')
                    ->label('Score')
                    ->placeholder('-')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('customer_type')
                    ->label('Customer type')
                    ->options(fn (): array => Customer::typeOptions()),

                SelectFilter::make('customer_source')
                    ->label('Customer source')
                    ->options(fn (): array => Customer::sourceOptions()),

                TernaryFilter::make('is_active')
                    ->label('Active status')
                    ->trueLabel('Active customers')
                    ->falseLabel('Inactive customers'),

                Filter::make('has_due')
                    ->label('Has due')
                    ->query(fn (Builder $query): Builder => $query->where('current_balance', '>', 0))
                    ->toggle(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
