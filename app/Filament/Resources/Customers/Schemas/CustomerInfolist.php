<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use App\Models\CustomerRiskProfile;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Customer')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('phone'),
                        TextEntry::make('email'),
                        TextEntry::make('customer_type')
                            ->label('Customer Type')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): ?string => Customer::typeLabel($state)),
                        TextEntry::make('customer_source')
                            ->label('Customer Source')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): ?string => Customer::sourceLabel($state))
                            ->placeholder('Not set'),
                        IconEntry::make('is_active')->boolean(),
                        TextEntry::make('address')
                            ->label('Customer Address')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Balance')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('opening_balance')->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('current_balance')->moneyWithoutTrailingZeroes('BDT'),
                    ])
                    ->columns(2),

                Section::make('Customer Success & Risk Score')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('riskProfile.risk_score')->label('Risk Score')->placeholder('Not evaluated'),
                        TextEntry::make('riskProfile.risk_level')->label('Risk Level')->badge()->placeholder('Not evaluated')
                            ->formatStateUsing(fn (?string $state): string => CustomerRiskProfile::LEVELS[$state ?? ''] ?? 'Not evaluated'),
                        TextEntry::make('riskProfile.success_ratio')->label('Success Ratio')->suffix('%')->placeholder('0%'),
                        TextEntry::make('riskProfile.return_ratio')->label('Return Ratio')->suffix('%')->placeholder('0%'),
                        TextEntry::make('riskProfile.cancel_ratio')->label('Cancel Ratio')->suffix('%')->placeholder('0%'),
                        TextEntry::make('riskProfile.evaluated_at')->label('Last Evaluated')->dateTime()->placeholder('Never'),
                    ])
                    ->columns(3),
            ]);
    }
}
