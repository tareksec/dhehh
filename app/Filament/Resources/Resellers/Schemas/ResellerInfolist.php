<?php

namespace App\Filament\Resources\Resellers\Schemas;

use App\Models\Customer;
use App\Services\ResellerOwnCourierService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ResellerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Applicant')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('phone'),
                        TextEntry::make('email')->placeholder('-'),
                        TextEntry::make('created_at')->label('Applied')->dateTime(),
                    ])
                    ->columns(2),

                Section::make('Reseller')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('reseller_status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): ?string => Customer::RESELLER_STATUSES[$state] ?? $state)
                            ->color(fn (?string $state): string => match ($state) {
                                'approved' => 'success',
                                'pending' => 'warning',
                                'rejected' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('business_name')->label('Business / shop name')->placeholder('-'),
                        TextEntry::make('resellerLevel.name')->label(__('Reseller level'))->badge()->placeholder('-'),
                        TextEntry::make('reseller_slug')
                            ->label('Store URL')
                            ->placeholder('Not approved yet')
                            ->formatStateUsing(fn (?string $state, Customer $record): ?string => filled($state) && $record->company?->domain
                                ? "{$record->company->domain}/store/{$state}"
                                : $state)
                            ->copyable(),
                        TextEntry::make('reseller_deposit')
                            ->label(__('Deposit'))
                            ->state(fn (Customer $record): float => app(ResellerOwnCourierService::class)->depositBalance($record))
                            ->numeric(decimalPlaces: 2),
                        TextEntry::make('reseller_note')->label('Note')->placeholder('-')->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }
}
