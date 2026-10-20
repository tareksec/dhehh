<?php

namespace App\Filament\Resources\InvestmentProjects\RelationManagers;

use App\Models\ProjectCostItem;
use App\Services\CompanyStorageService;
use App\Support\CompanyMedia;
use App\Support\ImsPanelLinks;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class CostItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'costItems';

    public function isReadOnly(): bool
    {
        return $this->getOwnerRecord()->status === 'settled' || parent::isReadOnly();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('category')->options(ProjectCostItem::CATEGORIES)->required(),
            TextInput::make('label')->required(),
            TextInput::make('amount')->numeric()->prefix('৳')->minValue(0)->required(),
            Select::make('purchase_id')->relationship('purchase', 'purchase_number')->searchable()->preload(),
            Textarea::make('remarks')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->withCount('documents'))->columns([
            TextColumn::make('category')
                ->badge()
                ->formatStateUsing(fn (string $state): string => ProjectCostItem::CATEGORIES[$state])
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('label')->searchable(),
            TextColumn::make('purchase.purchase_number')->label('Purchase')->placeholder('-'),
            TextColumn::make('amount')->moneyWithoutTrailingZeroes('BDT')->summarize(Sum::make()->moneyWithoutTrailingZeroes('BDT')),
            TextColumn::make('proof')
                ->label('Proof')
                ->badge()
                ->state(fn (ProjectCostItem $record): string => filled($record->purchase_id)
                    ? 'Purchase linked'
                    : (($record->documents_count ?? 0) > 0 ? 'Receipt attached' : 'Missing'))
                ->color(fn (string $state): string => $state === 'Missing' ? 'danger' : 'success'),
            TextColumn::make('remarks')->limit(40)->placeholder('-'),
        ])->groups([
            Group::make('category')
                ->label('Cost Category')
                ->getTitleFromRecordUsing(fn (ProjectCostItem $record): string => ProjectCostItem::CATEGORIES[$record->category])
                ->collapsible(),
        ])->defaultGroup('category')
            ->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data): array => [...$data, 'company_id' => $this->getOwnerRecord()->company_id])])
            ->recordActions([
                Action::make('attachReceipt')
                    ->label('Attach Receipt')
                    ->icon('heroicon-o-paper-clip')
                    ->modalHeading(fn (ProjectCostItem $record): string => "Receipt — {$record->label}")
                    ->schema([
                        TextInput::make('label')->maxLength(120)->placeholder('e.g. C&F invoice'),
                        FileUpload::make('file_path')
                            ->label('Receipt / Invoice')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(10240)
                            ->disk(fn (): string => app(CompanyStorageService::class)->privateDiskName())
                            ->directory(fn (): string => app(CompanyStorageService::class)->privateDirectory(CompanyMedia::require($this->getOwnerRecord()), 'investment-documents'))
                            ->visibility('private')
                            ->previewable(false)
                            ->required(),
                    ])
                    ->action(function (ProjectCostItem $record, array $data): void {
                        $record->documents()->create([
                            'company_id' => $record->company_id,
                            'category' => 'cost_receipt',
                            'label' => $data['label'] ?? null,
                            'file_path' => $data['file_path'],
                            'uploaded_by' => auth()->id(),
                        ]);
                    }),
                Action::make('downloadReceipt')
                    ->label('View Receipt')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (ProjectCostItem $record): bool => ($record->documents_count ?? 0) > 0)
                    ->url(fn (ProjectCostItem $record): ?string => ($doc = $record->documents()->latest('id')->first())
                        ? ImsPanelLinks::route('investment-documents.download', $doc)
                        : null)
                    ->openUrlInNewTab(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
