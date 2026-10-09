<?php

namespace App\Filament\Resources\StorefrontPages;

use App\Filament\Clusters\Storefront;
use App\Filament\Concerns\OptimizesUploadedImages;
use App\Filament\Concerns\SelectableFromMediaHub;
use App\Filament\Resources\StorefrontPages\Pages\CreateStorefrontPage;
use App\Filament\Resources\StorefrontPages\Pages\EditStorefrontPage;
use App\Filament\Resources\StorefrontPages\Pages\ListStorefrontPages;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Support\CompanyMedia;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Validation\Rule;

class StorefrontPageResource extends Resource
{
    use OptimizesUploadedImages;
    use SelectableFromMediaHub;

    protected static ?string $model = StorefrontPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $cluster = Storefront::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Pages';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Page')
                ->columnSpanFull()
                ->description('Create public storefront pages such as About, Return Policy, Privacy Policy, and Terms.')
                ->schema([
                    Select::make('company_id')
                        ->relationship('company', 'name', modifyQueryUsing: fn ($query) => CompanyMedia::constrainCompanyQuery($query))
                        ->rule(CompanyMedia::companyAccessRule())
                        ->required(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->visible(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->helperText('Select the company that will own this page.')
                        ->searchable()
                        ->preload()
                        ->live(),
                    Select::make('storefront_setting_id')
                        ->label(__('Show on'))
                        ->options(fn (Get $get, ?StorefrontPage $record): array => StorefrontSetting::siteChoicesFor(
                            (int) ($get('company_id') ?? $record?->company_id ?? app(CompanyContext::class)->id()) ?: null,
                        ))
                        ->rule(fn (Get $get, ?StorefrontPage $record) => Rule::exists('storefront_settings', 'id')
                            ->where('company_id', (int) ($get('company_id') ?? $record?->company_id ?? app(CompanyContext::class)->id())))
                        ->placeholder(__('All sites'))
                        ->helperText(__('Leave on "All sites" to show it on both the Blade storefront and the Next.js site, or pick one site to keep it off the other.')),
                    Toggle::make('is_published')
                        ->label('Published')
                        ->default(false)
                        ->helperText('Unpublished pages return a 404 publicly.'),
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(120)
                        ->alphaDash()
                        ->unique(table: 'storefront_pages', column: 'slug', ignoreRecord: true, modifyRuleUsing: fn ($rule, $get) => $rule->where('company_id', $get('company_id')))
                        ->helperText('Used in the public URL: /pages/your-slug'),
                    Textarea::make('excerpt')
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText('Short subtitle shown under the page title.')
                        ->columnSpanFull(),
                    FileUpload::make('cover_image')
                        ->label('Cover image (optional)')
                        ->helperText('Wide banner shown at the top of the page. Automatically compressed to WebP on upload.')
                        ->image()
                        ->maxSize(2048)
                        ->tap(static::browserImagePrecompression())
                        ->disk(fn (): string => CompanyMedia::publicDiskName())
                        ->directory(fn (Get $get, ?StorefrontPage $record): string => CompanyMedia::publicDirectory('storefront/pages', $record, $get('company_id')))
                        ->fetchFileInformation(false)
                        ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                        ->disabled(fn (Get $get, ?StorefrontPage $record): bool => ! CompanyMedia::canResolve($record, $get('company_id')))
                        ->imageEditor()
                        ->saveUploadedFileUsing(static::optimizeImageUpload())
                        ->hintAction(static::selectFromMediaHubAction())
                        ->columnSpanFull(),
                    RichEditor::make('content')
                        ->required()
                        ->columnSpanFull(),
                    TextInput::make('sort_order')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),
                ])
                ->columns(2),

            Section::make('SEO')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('meta_title')
                        ->maxLength(70),
                    Textarea::make('meta_description')
                        ->rows(3)
                        ->maxLength(160)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->badge()
                    ->searchable(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('show_on')
                    ->label(__('Show on'))
                    ->state(fn (StorefrontPage $record): string => $record->storefrontSetting?->siteTypeLabel() ?? __('All sites'))
                    ->badge()
                    ->color(fn (StorefrontPage $record): string => match (true) {
                        $record->storefront_setting_id === null => 'gray',
                        (bool) $record->storefrontSetting?->isNextjs() => 'success',
                        default => 'info',
                    }),
                IconColumn::make('is_published')
                    ->label('Published')
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
        return SchemaFacade::hasTable('storefront_pages') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canCreate(): bool
    {
        return SchemaFacade::hasTable('storefront_pages') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canEdit($record): bool
    {
        return SchemaFacade::hasTable('storefront_pages') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canDelete($record): bool
    {
        return SchemaFacade::hasTable('storefront_pages') && (Auth::user()?->isSuperAdmin() ?? false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStorefrontPages::route('/'),
            'create' => CreateStorefrontPage::route('/create'),
            'edit' => EditStorefrontPage::route('/{record}/edit'),
        ];
    }
}
