<?php

namespace App\Filament\Resources\QuickReplies;

use App\Filament\Clusters\Crm;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\Resources\QuickReplies\Pages\CreateQuickReply;
use App\Filament\Resources\QuickReplies\Pages\EditQuickReply;
use App\Filament\Resources\QuickReplies\Pages\ListQuickReplies;
use App\Models\QuickReply;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QuickReplyResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = QuickReply::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmark;

    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'Quick Reply';

    protected static ?string $pluralModelLabel = 'Quick Replies';

    protected static ?string $recordTitleAttribute = 'shortcut';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Quick Reply')->columnSpanFull()->schema([
                TextInput::make('shortcut')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('delivery')
                    ->helperText('Short label shown in the Inbox insert list - not sent to the customer.'),
                Textarea::make('body')
                    ->required()
                    ->rows(3)
                    ->helperText('Inserted into the reply box exactly as written, ready to edit before sending.'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('shortcut')->searchable()->limit(40),
            TextColumn::make('body')->placeholder('-')->limit(60),
            IconColumn::make('is_active')->label('Active')->boolean(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ]);
    }

    protected static function requiredPermissions(): array
    {
        return ['view' => 'crm.view', 'create' => 'crm.manage', 'update' => 'crm.manage', 'delete' => 'crm.manage'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuickReplies::route('/'),
            'create' => CreateQuickReply::route('/create'),
            'edit' => EditQuickReply::route('/{record}/edit'),
        ];
    }
}
