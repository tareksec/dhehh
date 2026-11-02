<?php

namespace App\Filament\Resources\Shipments;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Models\Shipment;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ShipmentResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Shipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAsiaAustralia;

    protected static string|UnitEnum|null $navigationGroup = 'Purchases';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('tracking_number')->required()->maxLength(100), TextInput::make('carrier')->maxLength(255),
            Select::make('container_id')->relationship('container', 'container_number')->searchable()->preload(),
            Select::make('purchase_id')->relationship('purchase', 'purchase_number')->searchable()->preload(),
            Select::make('transport_mode')->options(['sea' => 'Sea', 'air' => 'Air', 'road' => 'Road', 'rail' => 'Rail'])->required(),
            Select::make('status')->options(Shipment::STATUSES)->required(), DatePicker::make('shipped_at'), DatePicker::make('estimated_arrival'), DatePicker::make('received_at'), Textarea::make('notes')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('tracking_number')->searchable()->sortable(), TextColumn::make('purchase.purchase_number')->label('Purchase')->searchable(), TextColumn::make('container.container_number')->label('Container')->searchable(), TextColumn::make('carrier'), TextColumn::make('transport_mode')->badge(), TextColumn::make('status')->badge(), TextColumn::make('estimated_arrival')->date()->sortable(),
        ])->recordActions([EditAction::make()]);
    }

    protected static function requiredPermissions(): array
    {
        return ['view' => 'purchasing.view', 'create' => 'purchasing.create', 'update' => 'purchasing.update', 'delete' => 'purchasing.delete'];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListShipments::route('/'), 'create' => Pages\CreateShipment::route('/create'), 'edit' => Pages\EditShipment::route('/{record}/edit')];
    }
}
