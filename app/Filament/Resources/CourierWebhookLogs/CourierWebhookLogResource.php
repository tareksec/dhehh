<?php

namespace App\Filament\Resources\CourierWebhookLogs;

use App\Filament\Clusters\Courier;
use App\Filament\Resources\CourierWebhookLogs\Pages\ListCourierWebhookLogs;
use App\Models\CourierWebhookLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CourierWebhookLogResource extends Resource
{
    protected static ?string $model = CourierWebhookLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $cluster = Courier::class;

    protected static ?string $navigationLabel = 'Webhook Logs';

    protected static ?int $navigationSort = 4;

    protected static bool $shouldRegisterNavigation = false;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('provider.name')->label('Provider'),
            TextColumn::make('delivery_id')->label('Event ID')->searchable()->limit(28),
            TextColumn::make('event')->searchable(),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                'processed' => 'success', 'failed' => 'danger', 'ignored' => 'gray', default => 'warning'
            }),
            TextColumn::make('attempts')->numeric(),
            TextColumn::make('error')->limit(60)->placeholder('-'),
            TextColumn::make('processed_at')->dateTime()->placeholder('-'),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([SelectFilter::make('status')->options(['pending' => 'Pending', 'processed' => 'Processed', 'ignored' => __('Ignored'), 'failed' => 'Failed'])]);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
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

    public static function getPages(): array
    {
        return ['index' => ListCourierWebhookLogs::route('/')];
    }
}
