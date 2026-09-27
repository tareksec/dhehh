<x-filament-panels::page>
    @if ($this->hasSelectedCompany())
        <form id="integrations-form" wire:submit="save">
            {{ $this->form }}
        </form>

        <div class="mt-8">
            <h2 class="text-base font-semibold mb-3">Multi-provider integrations</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                Courier Providers and Meta Ads each manage many providers/ad accounts of their own, so they keep
                their full dedicated pages — this just shows whether at least one is connected.
            </p>
            @livewire(\App\Filament\Pages\IntegrationWidgets\IntegrationStatusWidget::class)
        </div>

        @php($aiProviderLinks = $this->aiToolProviderLinks())

        @if ($aiProviderLinks !== [])
            <div class="mt-8">
                <h2 class="text-base font-semibold mb-3">{{ __('AI tool providers') }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                    {{ __('Provider, model and API key setup for the AI Tools and Smart Scan. Each opens its own settings page.') }}
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($aiProviderLinks as $link)
                        <div class="fi-section relative flex items-start gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-primary-500 hover:shadow-md dark:border-white/10 dark:bg-gray-900 dark:hover:border-primary-500">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                                <x-filament::icon :icon="$link['icon']" class="h-5 w-5" />
                            </div>

                            <div class="min-w-0 space-y-1">
                                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $link['label'] }}</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $link['description'] }}</p>
                            </div>

                            <a
                                href="{{ $link['url'] }}"
                                wire:navigate
                                class="absolute inset-0 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                                aria-label="{{ __('Open :label', ['label' => $link['label']]) }}"
                            ></a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <x-filament::empty-state
            heading="Select a company to see its integration settings"
            description="Integrations are configured per company. Choose a company from the top-bar company switcher to load its settings."
            icon="heroicon-o-building-office-2"
        />
    @endif
</x-filament-panels::page>
