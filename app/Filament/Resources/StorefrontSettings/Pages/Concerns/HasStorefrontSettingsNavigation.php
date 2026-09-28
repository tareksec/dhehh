<?php

namespace App\Filament\Resources\StorefrontSettings\Pages\Concerns;

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

    /** @return array<int, array{key: string, label: string, icon: string}> */
    public function sectionNavigation(): array
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
            ['key' => 'reseller', 'label' => __('Reseller Settings'), 'icon' => 'heroicon-o-user-group'],
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
