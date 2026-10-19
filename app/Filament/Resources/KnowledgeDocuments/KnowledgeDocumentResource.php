<?php

namespace App\Filament\Resources\KnowledgeDocuments;

use App\Filament\Clusters\Crm;
use App\Filament\Resources\KnowledgeDocuments\Pages\CreateKnowledgeDocument;
use App\Filament\Resources\KnowledgeDocuments\Pages\EditKnowledgeDocument;
use App\Filament\Resources\KnowledgeDocuments\Pages\ListKnowledgeDocuments;
use App\Models\KnowledgeDocument;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The company's own documents for the sales agent (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.6).
 * What is written here is sent to customers as written, so only people who
 * can reply and manage the CRM can change it (same rule as Broadcasts).
 */
class KnowledgeDocumentResource extends Resource
{
    protected static ?string $model = KnowledgeDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('Knowledge base');
    }

    public static function getModelLabel(): string
    {
        return __('knowledge document');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Knowledge base');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('crm.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Document'))->columnSpanFull()->schema([
                TextInput::make('title')
                    ->label(__('Title'))
                    ->required()
                    ->maxLength(255),
                Textarea::make('content')
                    ->label(__('Content'))
                    ->required()
                    ->rows(16)
                    ->maxLength(KnowledgeDocument::MAX_CONTENT)
                    ->helperText(__('Write one topic under each heading (a line starting with #). The AI picks a short passage of up to about 700 characters and it is sent to the customer exactly as written, so put full sentences with the real numbers here.'))
                    ->hintAction(static::importAction()),
                Hidden::make('source_file'),
                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->helperText(__('Only active documents are used by the AI.'))
                    ->default(true),
            ]),
        ]);
    }

    protected static function importAction(): Action
    {
        return Action::make('importFile')
            ->label(__('Import file'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading(__('Import file'))
            ->modalDescription(__('Upload a .md or .txt file. Its text replaces the content above.'))
            ->schema([
                FileUpload::make('file')
                    ->label(__('File'))
                    ->helperText(__('Only the text of the file is read. Up to 200 KB.'))
                    ->storeFiles(false)
                    ->maxSize(200)
                    ->required(),
            ])
            ->action(function (array $data, Set $set, Get $get): void {
                $file = $data['file'] ?? null;
                if (! $file instanceof TemporaryUploadedFile) {
                    return;
                }
                $text = trim((string) $file->get());
                $set('content', Str::limit($text, KnowledgeDocument::MAX_CONTENT, ''));
                $set('source_file', Str::limit($file->getClientOriginalName(), 255, ''));
                if (blank($get('title'))) {
                    $set('title', Str::limit(
                        preg_match('/^\s{0,3}#\s+(.+)$/mu', $text, $heading) ? trim($heading[1]) : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                        255,
                        '',
                    ));
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('title')->label(__('Title'))->searchable()->limit(60),
            TextColumn::make('source_file')->label(__('File'))->placeholder('-')->limit(30),
            IconColumn::make('is_active')->label(__('Active'))->boolean(),
            TextColumn::make('updated_at')->label(__('Updated'))->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeDocuments::route('/'),
            'create' => CreateKnowledgeDocument::route('/create'),
            'edit' => EditKnowledgeDocument::route('/{record}/edit'),
        ];
    }
}
