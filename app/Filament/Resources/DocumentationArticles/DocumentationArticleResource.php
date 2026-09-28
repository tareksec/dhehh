<?php

namespace App\Filament\Resources\DocumentationArticles;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\DocumentationArticles\Pages\CreateDocumentationArticle;
use App\Filament\Resources\DocumentationArticles\Pages\EditDocumentationArticle;
use App\Filament\Resources\DocumentationArticles\Pages\ListDocumentationArticles;
use App\Models\DocumentationArticle;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;

class DocumentationArticleResource extends Resource
{
    protected static ?string $model = DocumentationArticle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('Documentation');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Article'))
                ->columnSpanFull()
                ->description(__('Step-by-step guidance shown to everyone in the app\'s Documentation menu.'))
                ->schema([
                    Select::make('documentation_category_id')
                        ->label(__('Category'))
                        ->relationship('category', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            TextInput::make('name')->label(__('Name'))->required()->maxLength(120),
                            TextInput::make('icon')
                                ->label(__('Icon'))
                                ->helperText(__('Heroicon name, e.g. heroicon-o-truck. Leave blank for the default.'))
                                ->maxLength(60),
                            TextInput::make('sort_order')->label(__('Sort order'))->numeric()->default(0),
                        ]),
                    Toggle::make('is_published')
                        ->label(__('Published'))
                        ->default(true)
                        ->helperText(__('Unpublished articles are hidden from the Documentation menu.')),
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true),
                    TextInput::make('slug')
                        ->label(__('Slug'))
                        ->required()
                        ->maxLength(120)
                        ->alphaDash()
                        ->unique(table: 'documentation_articles', column: 'slug', ignoreRecord: true, modifyRuleUsing: fn ($rule, $get) => $rule->where('documentation_category_id', $get('documentation_category_id'))),
                    TextInput::make('sort_order')
                        ->label(__('Sort order'))
                        ->numeric()
                        ->default(0)
                        ->minValue(0),
                    RichEditor::make('content')
                        ->label(__('Content'))
                        ->required()
                        ->columnSpanFull()
                        ->helperText(__('Write the guide as numbered steps — this is what staff will follow.')),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label(__('Category'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_published')
                    ->label(__('Published'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('Sort order'))
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('Updated at'))
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
        return SchemaFacade::hasTable('documentation_articles') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canCreate(): bool
    {
        return SchemaFacade::hasTable('documentation_articles') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canEdit($record): bool
    {
        return SchemaFacade::hasTable('documentation_articles') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function canDelete($record): bool
    {
        return SchemaFacade::hasTable('documentation_articles') && (Auth::user()?->canManageSettings() ?? false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentationArticles::route('/'),
            'create' => CreateDocumentationArticle::route('/create'),
            'edit' => EditDocumentationArticle::route('/{record}/edit'),
        ];
    }
}
