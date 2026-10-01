<x-filament-panels::page>
    @if ($storeUrl = $this->storeUrl())
        <x-filament::section>
            <div class="flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Your store is live at:') }}</div>
                    <div class="break-all font-medium">{{ $storeUrl }}</div>
                </div>
                <x-filament::button
                    size="sm"
                    color="gray"
                    x-on:click="window.navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($storeUrl) }}); $tooltip({{ \Illuminate\Support\Js::from(__('Copied to clipboard')) }}, { theme: $store.theme, timeout: 1500 })"
                >
                    {{ __('Copy link') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    @if ($status = $this->customDomainStatus())
        <x-filament::section>
            <div class="space-y-2 text-sm">
                @if ($status['verified'])
                    <div class="font-medium text-success-600 dark:text-success-400">{{ __('Verified: :domain is live.', ['domain' => $status['domain']]) }}</div>
                @else
                    <div class="font-medium">{{ __('Point :domain to us:', ['domain' => $status['domain']]) }}</div>
                    <ul class="list-disc space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                        <li>{{ __('At your domain provider, add a CNAME record for :domain pointing to :target (or an A record with the same IP address).', ['domain' => $status['domain'], 'target' => $status['target']]) }}</li>
                        <li>{{ __('Then press Check DNS. It can take a few hours for the change to spread.') }}</li>
                    </ul>
                    <x-filament::button size="sm" wire:click="verifyDomain">{{ __('Check DNS') }}</x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @endif

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit">
                {{ __('Save changes') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
