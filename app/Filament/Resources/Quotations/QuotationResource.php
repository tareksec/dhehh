<?php

namespace App\Filament\Resources\Quotations;

use App\Filament\Clusters\Crm;
use App\Filament\Concerns\TrashFollowsDelete;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\TrashedQuotations;
use App\Filament\Resources\Quotations\Schemas\QuotationForm;
use App\Filament\Resources\Quotations\Tables\QuotationsTable;
use App\Models\Quotation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QuotationResource extends Resource
{
    use TrashFollowsDelete;

    protected static ?string $model = Quotation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'quotation_number';

    public static function form(Schema $schema): Schema
    {
        return QuotationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuotationsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['lead', 'customer']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotations::route('/'),
            'create' => CreateQuotation::route('/create'),
            'trash' => TrashedQuotations::route('/trash'),
            'edit' => EditQuotation::route('/{record}/edit'),
        ];
    }
}
