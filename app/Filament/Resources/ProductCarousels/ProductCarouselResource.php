<?php

namespace App\Filament\Resources\ProductCarousels;

use App\Filament\Clusters\Storefront;
use App\Filament\Concerns\TrashFollowsDelete;
use App\Filament\Resources\ProductCarousels\Pages\CreateProductCarousel;
use App\Filament\Resources\ProductCarousels\Pages\EditProductCarousel;
use App\Filament\Resources\ProductCarousels\Pages\ListProductCarousels;
use App\Filament\Resources\ProductCarousels\Pages\TrashedProductCarousels;
use App\Models\ProductCarousel;
use App\Services\CompanyContext;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;

class ProductCarouselResource extends Resource
{
    use TrashFollowsDelete;

    protected static ?string $model = ProductCarousel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $cluster = Storefront::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationLabel = 'Homepage Carousels';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Carousel')
                ->columnSpanFull()
                ->description('Curated, titled product sections shown on the storefront homepage. Products are hand-picked; only active, available products are shown publicly.')
                ->schema([
                    Select::make('company_id')
                        ->relationship('company', 'name')
                        ->required(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->visible(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->helperText('Select the company that will own this carousel.')
                        ->searchable()
                        ->preload()
                        ->live(),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Inactive carousels are hidden from the storefront.'),
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Shown as the section heading, e.g. "Best Sellers".'),
                    TextInput::make('subtitle')
                        ->maxLength(255)
                        ->helperText('Optional short line under the heading.'),
                    TextInput::make('sort_order')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->helperText('Lower numbers appear first on the homepage.'),
                ])
                ->columns(2),

            Section::make('Products')
                ->columnSpanFull()
                ->description('Hand-pick the products for this carousel. Only products of the selected company are listed.')
                ->schema([
                    Select::make('products')
                        ->relationship(
                            name: 'products',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query, $get) => $query->when(
                                $get('company_id'),
                                fn (Builder $q, $companyId) => $q->where('company_id', $companyId),
                            ),
                        )
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpanFull()
                        ->helperText('Pick at least one product. Out-of-scope company products are never shown publicly.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('products_count')
                    ->counts('products')
                    ->label('Products'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function canViewAny(): bool
    {
        return SchemaFacade::hasTable('product_carousels') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canCreate(): bool
    {
        return SchemaFacade::hasTable('product_carousels') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canEdit($record): bool
    {
        return SchemaFacade::hasTable('product_carousels') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canDelete($record): bool
    {
        return SchemaFacade::hasTable('product_carousels') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canDeleteAny(): bool
    {
        return SchemaFacade::hasTable('product_carousels') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductCarousels::route('/'),
            'trash' => TrashedProductCarousels::route('/trash'),
            'create' => CreateProductCarousel::route('/create'),
            'edit' => EditProductCarousel::route('/{record}/edit'),
        ];
    }
}
