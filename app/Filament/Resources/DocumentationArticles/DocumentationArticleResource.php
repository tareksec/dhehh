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

    protected static ?string $navigationLabel = 'Documentation';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Article')
                ->columnSpanFull()
                ->description('Step-by-step guidance shown to everyone in the app\'s Documentation menu.')
                ->schema([
                    Select::make('documentation_category_id')
                        ->label('Category')
                        ->relationship('category', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            TextInput::make('name')->required()->maxLength(120),
                            TextInput::make('icon')
                                ->helperText('Heroicon name, e.g. heroicon-o-truck. Leave blank for the default.')
                                ->maxLength(60),
                            TextInput::make('sort_order')->numeric()->default(0),
                        ]),
                    Toggle::make('is_published')
                        ->label('Published')
                        ->default(true)
                        ->helperText('Unpublished articles are hidden from the Documentation menu.'),
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(120)
                        ->alphaDash()
                        ->unique(table: 'documentation_articles', column: 'slug', ignoreRecord: true, modifyRuleUsing: fn ($rule, $get) => $rule->where('documentation_category_id', $get('documentation_category_id'))),
                    TextInput::make('sort_order')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),
                    RichEditor::make('content')
                        ->required()
                        ->columnSpanFull()
                        ->helperText('Write the guide as numbered steps — this is what staff will follow.'),
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
                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->searchable()
                    ->sortable(),
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
