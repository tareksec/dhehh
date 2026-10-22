<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Filament\Concerns\OptimizesUploadedImages;
use App\Filament\Concerns\SelectableFromMediaHub;
use App\Filament\Forms\Components\EmailInput;
use App\Filament\Forms\Components\PhoneInput;
use App\Models\Supplier;
use App\Support\CompanyMedia;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierForm
{
    use OptimizesUploadedImages;
    use SelectableFromMediaHub;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Supplier Information')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('company_name')
                            ->label('Company Name')
                            ->maxLength(255),

                        PhoneInput::make(),

                        EmailInput::make(),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Balance')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('opening_balance')
                            ->numeric()
                            ->prefix('৳')
                            ->default(0)
                            ->required(),

                        TextInput::make('current_balance')
                            ->numeric()
                            ->prefix('৳')
                            ->default(0)
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Current balance is updated from received purchases.'),
                    ])
                    ->columns(2),

                Section::make('Address')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('address')
                            ->rows(3)
                            ->columnSpanFull(),

                        TextInput::make('country')
                            ->maxLength(255),

                        TextInput::make('fax')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make(__('Trade & Banking Details'))
                    ->description(__('Printed as the Seller/Beneficiary block on this supplier\'s Purchase PI/CI/Packing List documents.'))
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('bank_name')
                            ->maxLength(255),

                        TextInput::make('bank_account_name')
                            ->label(__('Beneficiary Account Name'))
                            ->maxLength(255),

                        TextInput::make('bank_account_number')
                            ->label(__('Beneficiary Account Number'))
                            ->maxLength(255),

                        TextInput::make('bank_swift_code')
                            ->label(__('SWIFT Code'))
                            ->maxLength(50),

                        Textarea::make('bank_address')
                            ->label(__('Bank Address'))
                            ->rows(2)
                            ->columnSpanFull(),

                        TextInput::make('bank_extra_note')
                            ->label(__('Additional Bank Reference'))
                            ->placeholder(__('e.g. CNAPS2 code, routing number'))
                            ->maxLength(255)
                            ->columnSpanFull(),

                        FileUpload::make('signature_path')
                            ->label(__('Authorized Signature / Stamp'))
                            ->helperText(__('Save the supplier first, then upload. Printed on the PI/CI/Packing List signature block.'))
                            ->image()
                            ->tap(static::browserCompactImagePrecompression())
                            ->disk(fn (): string => CompanyMedia::publicDiskName())
                            ->directory(fn (?Supplier $record): string => CompanyMedia::publicDirectory('suppliers', $record))
                            ->fetchFileInformation(false)
                            ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                            ->getOpenableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                            ->getDownloadableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                            ->disabled(fn (?Supplier $record): bool => ! CompanyMedia::canResolve($record))
                            ->imageEditor()
                            ->saveUploadedFileUsing(static::optimizeCompactImageUpload())
                            ->hintAction(static::selectFromMediaHubAction())
                            ->downloadable()
                            ->openable(),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
