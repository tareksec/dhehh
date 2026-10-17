<x-filament-panels::page>
    @if ($storeUrl = $this->storeUrl())
        <x-filament::section>
            <div class="flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Your store is live at:') }}</div>
                    <div class="break-all font-medium">{{ $storeUrl }}</div>
                </div>
                <x-filament::button
                    tag="a"
                    size="sm"
                    icon="heroicon-m-arrow-top-right-on-square"
                    :href="$storeUrl"
                    target="_blank"
                    rel="noopener"
                >
                    {{ __('Visit store') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    @if ($subdomain = $this->subdomainStatus())
        <x-filament::section>
            @if ($subdomain['live'])
                <div class="text-sm font-medium text-success-600 dark:text-success-400">
                    {{ __('Your store address :host is live.', ['host' => $subdomain['host']]) }}
                </div>
            @else
                <div
                    wire:poll.15s="checkSubdomain"
                    x-data="{
                        readyAt: new Date(@js($subdomain['ready_at'])).getTime(),
                        left: 0,
                        tick() { this.left = Math.max(0, Math.floor((this.readyAt - Date.now()) / 1000)) },
                        get clock() { return String(Math.floor(this.left / 60)).padStart(2, '0') + ':' + String(this.left % 60).padStart(2, '0') },
                    }"
                    x-init="tick(); setInterval(() => tick(), 1000)"
                    class="space-y-2 text-sm"
                >
                    <div class="flex items-center gap-2 font-medium">
                        <x-filament::loading-indicator class="h-4 w-4" />
                        {{ __('Setting up :host (DNS and SSL)', ['host' => $subdomain['host']]) }}
                    </div>
                    <div x-show="left > 0">
                        {{ __('Expected to be live in') }}
                        <span class="font-mono text-base font-semibold" x-text="clock"></span>
                    </div>
                    <div x-show="left === 0" x-cloak class="text-warning-600 dark:text-warning-400">
                        {{ __('This is taking longer than usual. We keep checking automatically.') }}
                    </div>
                    <div class="text-gray-500 dark:text-gray-400">
                        {{ __('Nothing to do on your side. Until it is ready, share your store link above; it switches to the new address on its own.') }}
                    </div>
                </div>
            @endif
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
