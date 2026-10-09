<x-filament-panels::page>
    @php($url = $this->inviteUrl())

    <x-filament::section :heading="__('Your invite link')">
        @if ($url)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Send this link to someone who wants to invest. They apply with it, and the company office reviews the application.') }}</p>
            <div class="mt-3 break-all rounded-lg bg-gray-50 p-3 font-mono text-sm dark:bg-white/5">{{ $url }}</div>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-filament::button size="sm" color="gray" icon="heroicon-o-clipboard"
                    x-data="{}" x-on:click="navigator.clipboard.writeText(@js($url)); $tooltip(@js(__('Copied')))">
                    {{ __('Copy link') }}
                </x-filament::button>
                <x-filament::button size="sm" color="success" icon="heroicon-o-share" tag="a" target="_blank"
                    :href="'https://wa.me/?text='.rawurlencode($url)">
                    {{ __('Share on WhatsApp') }}
                </x-filament::button>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Your invite link is not switched on. Please ask the company office.') }}</p>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('People who applied through you')">
        @php($applicants = $this->applicants())
        @if ($applicants->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing to show yet.') }}</p>
        @else
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($applicants as $applicant)
                    <div class="flex items-center justify-between gap-3 py-2 text-sm">
                        <div>
                            <div class="font-medium">{{ $applicant['name'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $applicant['date']?->format('d M Y') }}</div>
                        </div>
                        <x-filament::badge class="shrink-0">{{ $applicant['status'] }}</x-filament::badge>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
