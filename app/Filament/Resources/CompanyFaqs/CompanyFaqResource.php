<?php

namespace App\Filament\Resources\CompanyFaqs;

use App\Filament\Clusters\Crm;
use App\Filament\Concerns\TrashFollowsDelete;
use App\Filament\Resources\CompanyFaqs\Pages\CreateCompanyFaq;
use App\Filament\Resources\CompanyFaqs\Pages\EditCompanyFaq;
use App\Filament\Resources\CompanyFaqs\Pages\ListCompanyFaqs;
use App\Filament\Resources\CompanyFaqs\Pages\TrashedCompanyFaqs;
use App\Models\CompanyFaq;
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

class CompanyFaqResource extends Resource
{
    use TrashFollowsDelete;

    protected static ?string $model = CompanyFaq::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'FAQ';

    protected static ?string $pluralModelLabel = 'FAQs';

    protected static ?string $recordTitleAttribute = 'question';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('FAQ Entry')->columnSpanFull()->schema([
                TextInput::make('question')
                    ->required()
                    ->maxLength(255),
                Textarea::make('answer')
                    ->required()
                    ->rows(3)
                    ->helperText('Sent to the customer exactly as written when this FAQ matches.'),
                TextInput::make('keywords')
                    ->maxLength(255)
                    ->placeholder('ডেলিভারি, delivery charge, কত দিন')
                    ->helperText('Comma-separated trigger words. If a customer message contains one, this answer is sent instantly (no AI call).'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('question')->searchable()->limit(60),
            TextColumn::make('keywords')->placeholder('-')->limit(40),
            IconColumn::make('is_active')->label('Active')->boolean(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyFaqs::route('/'),
            'trash' => TrashedCompanyFaqs::route('/trash'),
            'create' => CreateCompanyFaq::route('/create'),
            'edit' => EditCompanyFaq::route('/{record}/edit'),
        ];
    }
}
