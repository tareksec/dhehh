<?php

namespace App\Filament\Resources\Resellers\Schemas;

use App\Models\Customer;
use App\Models\ResellerLevel;
use App\Services\CompanyContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class ResellerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Applicant')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->required()
                            ->maxLength(40),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Reseller')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('reseller_status')
                            ->label('Status')
                            ->options(Customer::RESELLER_STATUSES)
                            ->required(),
                        TextInput::make('business_name')
                            ->label('Business / shop name')
                            ->maxLength(255),
                        // Only this reseller's own company's levels can be picked (owner decision 2026-10-16).
                        Select::make('reseller_level_id')
                            ->label(__('Reseller level'))
                            ->helperText(__('Set by hand. The reseller sees the level name, and its return charge and minimum withdrawal apply when the level sets them.'))
                            ->options(fn (?Customer $record): array => ResellerLevel::withoutGlobalScopes()
                                ->where('company_id', $record?->company_id ?? app(CompanyContext::class)->id())
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->placeholder(__('No level'))
                            ->native(false),
                        TextInput::make('reseller_slug')
                            ->label('Store URL slug')
                            ->helperText('The public store is reachable at {your-domain}/store/{slug}. Unique per company. Leave blank to auto-generate on approval.')
                            ->maxLength(255)
                            ->rule('alpha_dash')
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: function (Unique $rule, ?Customer $record): Unique {
                                    $companyId = $record?->company_id ?? app(CompanyContext::class)->company()?->id;

                                    return $rule->where('company_id', $companyId);
                                },
                            ),
                        Textarea::make('reseller_note')
                            ->label('Application / rejection note')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
