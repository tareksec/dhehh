<?php

namespace App\Filament\Resources\InvestmentProjects\RelationManagers;

use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\InvestmentDocument;
use App\Models\Investor;
use App\Services\CompanyStorageService;
use App\Support\CompanyMedia;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Polymorphic document store — registered on InvestmentProjectResource,
 * ProjectSettlementResource and InvestmentRecordResource (v3 P1.1). Every
 * host model exposes a `documents()` morphMany.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    /**
     * Shared across InvestmentProjectResource, ProjectSettlementResource and
     * InvestmentRecordResource (see class docblock) — the latter two never
     * register an Edit page. Filament's panel-wide default freezes relation
     * managers on any ViewRecord page, which would make document upload
     * permanently impossible on those two (v3 P3 audit). Always writable
     * here; real authorization still comes from the `investments` Gate on
     * InvestmentDocument's create/update/delete abilities.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /** Investor and channel partner documents are NID copies and contracts: investments.manage only. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if ($ownerRecord instanceof Investor || $ownerRecord instanceof ChannelPartner) {
            return auth()->user()?->hasPermission('investments.manage') ?? false;
        }

        return parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Documents');
    }

    protected function ownerCompany(): Company
    {
        return CompanyMedia::require($this->getOwnerRecord());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('category')->label(__('Category'))->options(InvestmentDocument::categoryOptions())->default('other')->required(),
            TextInput::make('label')->label(__('Label'))->maxLength(120)->placeholder(__('e.g. Settlement sheet — cycle 1')),
            FileUpload::make('file_path')
                ->label(__('File'))
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(10240)
                ->disk(fn (): string => app(CompanyStorageService::class)->privateDiskName())
                ->directory(fn (): string => app(CompanyStorageService::class)->privateDirectory($this->ownerCompany(), 'investment-documents'))
                ->visibility('private')
                ->previewable(false)
                ->openable(false)
                ->required()
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => auth()->user()?->hasPermission('investments.manage')
            ? $query
            : $query->whereNotIn('category', InvestmentDocument::SENSITIVE_CATEGORIES))->columns([
                TextColumn::make('category')
                    ->label(__('Category'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => InvestmentDocument::categoryLabel($state)),
                TextColumn::make('label')->label(__('Label'))->limit(40)->placeholder('-'),
                TextColumn::make('uploadedBy.name')->label(__('Uploaded by'))->placeholder('-'),
                TextColumn::make('created_at')->label(__('Uploaded at'))->dateTime()->sortable(),
            ])->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data): array => [
                    ...$data,
                    'company_id' => $this->getOwnerRecord()->company_id,
                    'uploaded_by' => auth()->id(),
                ]),
            ])->recordActions([
                Action::make('download')
                    ->label(__('Download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (InvestmentDocument $record): string => route('investment-documents.download', $record))
                    ->openUrlInNewTab(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
