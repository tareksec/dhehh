<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Filament\Concerns\OptimizesUploadedImages;
use App\Filament\Concerns\SelectableFromMediaHub;
use App\Filament\Forms\Components\CategoryIconPicker;
use App\Support\CompanyMedia;
use App\Support\StorefrontCategoryIcons;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CategoryForm
{
    use OptimizesUploadedImages;
    use SelectableFromMediaHub;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('tagline')
                    ->label(__('Tagline'))
                    ->maxLength(80)
                    ->helperText(__('A short line (for example "Earbuds · TWS · Headsets") shown under the category name on the Next.js website\'s category cards.'))
                    ->columnSpanFull(),
                ...self::mediaFields(),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public static function mediaFields(): array
    {
        return [self::imageField(), self::iconField()];
    }

    public static function imageField(): FileUpload
    {
        return FileUpload::make('image')
            ->label('Category image')
            ->helperText('Recommended: square, at least 400×400px. The image is automatically compressed to WebP and takes priority over the selected icon.')
            ->image()
            ->maxSize(1024)
            ->tap(static::browserCompactImagePrecompression())
            ->disk(fn (): string => CompanyMedia::publicDiskName())
            ->directory(fn ($record): string => CompanyMedia::publicDirectory('categories', $record))
            ->fetchFileInformation(false)
            ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
            ->getOpenableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
            ->getDownloadableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
            ->disabled(fn ($record): bool => ! CompanyMedia::canResolve($record))
            ->imageEditor()
            ->saveUploadedFileUsing(static::optimizeCompactImageUpload())
            ->hintAction(static::selectFromMediaHubAction())
            ->downloadable()
            ->openable()
            ->columnSpanFull();
    }

    public static function iconField(): CategoryIconPicker
    {
        return CategoryIconPicker::make('icon')
            ->label('Category icon')
            ->live()
            ->dehydrateStateUsing(fn (?string $state): ?string => StorefrontCategoryIcons::normalize($state))
            ->helperText('Used when no category image is uploaded. Leave empty to show the first letter of the category name.')
            ->columnSpanFull();
    }
}
