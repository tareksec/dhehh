<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CustomerRiskProfile;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class CustomerRiskAlerts extends TableWidget
{
    use GuardsWidgetRendering;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return Schema::hasTable('customer_risk_profiles') && (Auth::user()?->hasPermission('sales.view') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Customer Risk Alerts')
            ->description('High-risk and blacklisted customer profiles that need review before shipping.')
            ->query(fn (): Builder => CustomerRiskProfile::query()
                ->with('customer')
                ->whereIn('risk_level', [CustomerRiskProfile::LEVEL_HIGH, CustomerRiskProfile::LEVEL_BLACKLISTED])
                ->orderBy('risk_score')
                ->orderByDesc('evaluated_at'))
            ->columns([
                TextColumn::make('customer.name')->label('Customer')->searchable()->placeholder('-'),
                TextColumn::make('phone')->searchable(),
                TextColumn::make('risk_score')->label('Score')->sortable(),
                TextColumn::make('risk_level')->badge()->formatStateUsing(fn (string $state): string => CustomerRiskProfile::LEVELS[$state] ?? $state)
                    ->color('danger'),
                TextColumn::make('return_ratio')->suffix('%')->sortable(),
                TextColumn::make('cancel_ratio')->suffix('%')->sortable(),
                TextColumn::make('evaluated_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('viewCustomer')
                    ->label('View customer')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (CustomerRiskProfile $record): ?string => $record->customer_id ? CustomerResource::getUrl('view', ['record' => $record->customer_id]) : null)
                    ->visible(fn (CustomerRiskProfile $record): bool => filled($record->customer_id)),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
