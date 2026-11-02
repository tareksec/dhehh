<?php

namespace App\Filament\Resources\Containers;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Models\Container;
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

class ContainerResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Container::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Purchases';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('container_number')->required()->maxLength(100), TextInput::make('shipping_line')->maxLength(255),
            TextInput::make('origin')->maxLength(255), TextInput::make('destination')->maxLength(255), Select::make('status')->options(Container::STATUSES)->required(),
            DatePicker::make('estimated_departure'), DatePicker::make('actual_departure'), DatePicker::make('estimated_arrival'), DatePicker::make('actual_arrival'), Textarea::make('notes')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('container_number')->searchable()->sortable(), TextColumn::make('shipping_line')->searchable(), TextColumn::make('origin'), TextColumn::make('destination'), TextColumn::make('status')->badge(), TextColumn::make('estimated_arrival')->date()->sortable(), TextColumn::make('shipments_count')->counts('shipments')->label('Shipments'),
        ])->recordActions([EditAction::make()]);
    }

    protected static function requiredPermissions(): array
    {
        return ['view' => 'purchasing.view', 'create' => 'purchasing.create', 'update' => 'purchasing.update', 'delete' => 'purchasing.delete'];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListContainers::route('/'), 'create' => Pages\CreateContainer::route('/create'), 'edit' => Pages\EditContainer::route('/{record}/edit')];
    }
}
