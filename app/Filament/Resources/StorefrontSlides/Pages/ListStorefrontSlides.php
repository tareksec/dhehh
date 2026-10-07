<?php

namespace App\Filament\Resources\StorefrontSlides\Pages;

use App\Filament\Resources\StorefrontSlides\StorefrontSlideResource;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListStorefrontSlides extends ListRecords
{
    protected static string $resource = StorefrontSlideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->paginationDisplayAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Show/hide the hero banner's dot navigation, independently per display
     * type. The values live on the company's StorefrontSetting (both default
     * on); the storefront applies them as `.storefront-image-banner-hide-nav-*`
     * classes — see resources/css/app.css and the image-banner partial.
     */
    protected function paginationDisplayAction(): Action
    {
        return Action::make('paginationDisplay')
            ->label('Pagination display')
            ->icon('heroicon-o-view-columns')
            ->modalHeading('Banner pagination display')
            ->modalDescription('Show or hide the hero banner\'s dot navigation for each display type. This applies to the whole banner carousel, not a single slide.')
            ->modalSubmitActionLabel('Save')
            ->visible(fn (): bool => static::activeStorefrontSetting() !== null)
            ->fillForm(fn (): array => [
                'banner_pagination_desktop' => static::activeStorefrontSetting()?->showsBannerPagination('desktop') ?? true,
                'banner_pagination_mobile' => static::activeStorefrontSetting()?->showsBannerPagination('mobile') ?? true,
            ])
            ->schema([
                Toggle::make('banner_pagination_desktop')
                    ->label('Show pagination on desktop')
                    ->helperText('Screens 1024px and wider.')
                    ->default(true),
                Toggle::make('banner_pagination_mobile')
                    ->label('Show pagination on mobile')
                    ->helperText('Screens narrower than 1024px.')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                $setting = static::activeStorefrontSetting();

                if ($setting === null) {
                    return;
                }

                $setting->update([
                    'banner_pagination_desktop' => (bool) $data['banner_pagination_desktop'],
                    'banner_pagination_mobile' => (bool) $data['banner_pagination_mobile'],
                ]);

                Notification::make()
                    ->success()
                    ->title('Banner pagination display updated')
                    ->send();
            });
    }

    /** The active company's storefront settings row, or null when none exists yet / all companies is selected. */
    protected static function activeStorefrontSetting(): ?StorefrontSetting
    {
        $companyId = app(CompanyContext::class)->id();

        return $companyId
            ? StorefrontSetting::bladeFor($companyId)
            : null;
    }
}
