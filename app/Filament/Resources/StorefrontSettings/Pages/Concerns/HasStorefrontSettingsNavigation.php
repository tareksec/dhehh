<?php

namespace App\Filament\Resources\StorefrontSettings\Pages\Concerns;

use App\Filament\Resources\StorefrontSettings\StorefrontSettingResource;
use App\Models\StorefrontSetting;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

trait HasStorefrontSettingsNavigation
{
    #[Url(as: 'section', history: true)]
    public string $activeSection = 'publishing';

    public function getHeader(): ?View
    {
        return view('filament.resources.storefront-settings.partials.settings-header', [
            'activeSection' => $this->activeSection,
            'breadcrumbs' => filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
            'headerActions' => $this->getCachedHeaderActions(),
            'headerActionsAlignment' => $this->getHeaderActionsAlignment(),
            'heading' => $this->getHeading(),
            'sectionItems' => $this->sectionNavigation(),
            'subheading' => $this->getSubheading(),
        ]);
    }

    public function selectSection(string $section): void
    {
        if (! in_array($section, $this->availableSectionKeys(), true)) {
            return;
        }

        $this->activeSection = $section;
    }

    public function updatedActiveSection(): void
    {
        $this->normalizeActiveSection();
    }

    public function isStorefrontSectionActive(string $section): bool
    {
        return $this->activeSection === $section;
    }

    /** Which kind of site is being edited (or, on the create page, chosen). */
    public function currentSiteType(): string
    {
        $type = ($this->record?->site_type ?? null) ?: data_get($this->data ?? [], 'site_type');

        return $type === StorefrontSetting::SITE_NEXTJS ? StorefrontSetting::SITE_NEXTJS : StorefrontSetting::SITE_BLADE;
    }

    /** Picking the other site type on the create page may hide the open section. */
    public function updatedDataSiteType(): void
    {
        $this->normalizeActiveSection();
    }

    /** @return array<int, array{key: string, label: string, icon: string}> */
    public function sectionNavigation(): array
    {
        $siteType = $this->currentSiteType();

        return array_values(array_filter($this->allSections(), fn (array $item): bool => StorefrontSettingResource::sectionAppliesToSite($item['key'], $siteType)));
    }

    /** @return array<int, array{key: string, label: string, icon: string}> */
    protected function allSections(): array
    {
        return [
            ['key' => 'publishing', 'label' => 'Publishing', 'icon' => 'heroicon-o-globe-alt'],
            ['key' => 'theme', 'label' => 'Theme & Layout', 'icon' => 'heroicon-o-swatch'],
            ['key' => 'design', 'label' => 'Design System', 'icon' => 'heroicon-o-paint-brush'],
            ['key' => 'homepage', 'label' => 'Homepage', 'icon' => 'heroicon-o-home'],
            ['key' => 'launch_branding', 'label' => 'Launch & Branding', 'icon' => 'heroicon-o-rocket-launch'],
            ['key' => 'checkout', 'label' => 'Checkout & Protection', 'icon' => 'heroicon-o-shopping-bag'],
            ['key' => 'integrations', 'label' => 'Payments & Integrations', 'icon' => 'heroicon-o-credit-card'],
            ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'heroicon-o-bell-alert'],
            ['key' => 'navigation_seo', 'label' => 'Navigation & SEO', 'icon' => 'heroicon-o-bars-3-bottom-left'],
            ['key' => 'nextjs_site', 'label' => __('Next.js Site'), 'icon' => 'heroicon-o-computer-desktop'],
        ];
    }

    protected function normalizeActiveSection(): void
    {
        if (! in_array($this->activeSection, $this->availableSectionKeys(), true)) {
            $this->activeSection = 'publishing';
        }
    }

    /** @return array<int, string> */
    protected function availableSectionKeys(): array
    {
        return array_column($this->sectionNavigation(), 'key');
    }
}
