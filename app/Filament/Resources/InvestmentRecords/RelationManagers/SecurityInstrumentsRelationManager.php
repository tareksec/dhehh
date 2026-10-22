<?php

namespace App\Filament\Resources\InvestmentRecords\RelationManagers;

use App\Models\InvestorSecurityInstrument;
use App\Services\CompanyStorageService;
use App\Support\CompanyMedia;
use App\Support\ImsPanelLinks;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SecurityInstrumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'securityInstruments';

    /**
     * `InvestmentRecordResource` never registers an Edit page (the investment
     * itself is edited from the project's InvestmentsRelationManager) — it
     * has only a View page. Filament's panel-wide default freezes relation
     * managers on any ViewRecord page, which would make this one permanently
     * read-only with no writable page to fall back to (v3 P3 audit — no
     * security instrument could ever be recorded through the admin UI).
     * Always writable here; real authorization still comes from the
     * `investments` Gate on InvestorSecurityInstrument's create/update/delete
     * abilities.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contract (ডিট পেপার)')->schema([
                DatePicker::make('contract_date'),
                TextInput::make('contract_reference')->placeholder('Project-01/2026-INV-01')
                    ->default(fn (): ?string => $this->getOwnerRecord()->project?->project_code),
                TagsInput::make('stamp_serial_numbers')
                    ->label('Stamp Serial Numbers')
                    ->helperText('৩টি ৳১০০ স্ট্যাম্পের সিরিয়াল নম্বর — প্রতিটি লিখে Enter চাপুন।')
                    ->columnSpanFull(),
                FileUpload::make('contract_document_path')
                    ->label('Signed Contract Scan')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(10240)
                    ->disk(fn (): string => app(CompanyStorageService::class)->privateDiskName())
                    ->directory(fn (): string => app(CompanyStorageService::class)->privateDirectory(CompanyMedia::require($this->getOwnerRecord()), 'investor-contracts'))
                    ->columnSpanFull(),
            ])->columns(2),
            Section::make('Security Cheque')->schema([
                TextInput::make('cheque_number'),
                TextInput::make('cheque_bank_name')->label('Bank'),
                TextInput::make('cheque_branch')->label('Branch'),
                TextInput::make('cheque_account_number'),
                TextInput::make('cheque_account_holder')->placeholder('TASNEEM KNITTING INDUSTRY'),
                TextInput::make('cheque_amount')->numeric()->prefix('৳')->default(fn (): float => (float) $this->getOwnerRecord()->amount)
                    ->helperText('সাধারণত investment amount-এর সমান (মূলধনের ১০০%)।'),
                Select::make('cheque_status')->options(InvestorSecurityInstrument::CHEQUE_STATUSES)->default('held_by_investor')->required(),
                Toggle::make('investor_signed_cheque_terms')
                    ->label('Investor signed "won\'t cash unless breach"')
                    ->helperText('চুক্তির ধারা ৭ — বিনিয়োগকারী স্বাক্ষর করেছেন যে চুক্তিভঙ্গ ছাড়া চেক ভাঙাবেন না।'),
            ])->columns(2),
            Section::make('Guarantor')->schema([
                TextInput::make('guarantor_name'),
                TextInput::make('guarantor_nid')->label('Guarantor NID / Passport'),
                TextInput::make('guarantor_phone')->tel(),
                TextInput::make('guarantor_relation')->label('Relation'),
                Textarea::make('guarantor_address')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('cheque_number')->placeholder('-'),
            TextColumn::make('cheque_bank_name')->label('Bank')->placeholder('-'),
            TextColumn::make('cheque_amount')->moneyWithoutTrailingZeroes('BDT')->placeholder('-'),
            TextColumn::make('cheque_status')->badge(),
            TextColumn::make('guarantor_name')->placeholder('-'),
            TextColumn::make('contract_document_path')->label('Contract')->formatStateUsing(fn (?string $state): string => $state ? 'Uploaded' : 'Not uploaded')->badge(),
        ])->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data): array => [...$data, 'company_id' => $this->getOwnerRecord()->company_id])])
            ->recordActions([
                Action::make('downloadContract')
                    ->label('Download Contract')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (InvestorSecurityInstrument $record): string => ImsPanelLinks::route('investor-security-instruments.contract', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (InvestorSecurityInstrument $record): bool => filled($record->contract_document_path)),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
