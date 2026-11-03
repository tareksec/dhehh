<div class="zz-storefront-settings-custom-header">
    {{-- Phones and tablets: just the three-line button, at the right end of the header's button row (theme CSS in settings-page.blade.php). The list opens from the right edge; the section you are on is highlighted in it. --}}
    <div class="zz-storefront-settings-mobile-nav" aria-label="Storefront settings mobile navigation">
        <x-filament::dropdown placement="bottom-end" width="sm" shift>
            <x-slot name="trigger">
                <x-filament::icon-button
                    color="gray"
                    :icon="\Filament\Support\Icons\Heroicon::OutlinedBars3"
                    icon-size="lg"
                    size="xl"
                    label="Open Storefront settings navigation"
                    class="zz-storefront-settings-mobile-nav-toggle"
                />
            </x-slot>

            <x-filament::dropdown.list>
                @foreach ($sectionItems as $item)
                    <x-filament::dropdown.list.item
                        :icon="$item['icon']"
                        :color="$activeSection === $item['key'] ? 'primary' : 'gray'"
                        :aria-current="$activeSection === $item['key'] ? 'page' : null"
                        :data-active="$activeSection === $item['key'] ? 'true' : 'false'"
                        :data-section="$item['key']"
                        wire:click="selectSection('{{ $item['key'] }}')"
                        wire:target="selectSection"
                        x-on:click="close()"
                        wire:key="storefront-settings-mobile-section-{{ $item['key'] }}"
                        class="{{ $activeSection === $item['key'] ? 'fi-selected' : '' }}"
                    >
                        {{ $item['label'] }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    </div>

    <x-filament-panels::header
        :actions="$headerActions"
        :actions-alignment="$headerActionsAlignment"
        :breadcrumbs="$breadcrumbs"
        :heading="$heading"
        :subheading="$subheading"
    />
</div>
