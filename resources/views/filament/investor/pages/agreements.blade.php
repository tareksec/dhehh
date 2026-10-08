<x-filament-panels::page>
    @forelse ($this->outstanding() as $template)
        <x-filament::section :heading="$template->title" :description="__('Version :version', ['version' => $template->version])">
            <div class="max-h-96 overflow-y-auto whitespace-pre-line text-sm leading-relaxed">{{ $template->body }}</div>
            <div class="mt-4">
                <x-filament::button wire:click="accept({{ $template->getKey() }})" wire:confirm="{{ __('I have read and agree to the :title.', ['title' => $template->title]) }}">
                    {{ __('I have read and agree') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing waiting for you to accept.') }}</p>
        </x-filament::section>
    @endforelse

    @php($accepted = $this->accepted())
    @if ($accepted->isNotEmpty())
        <x-filament::section :heading="__('Accepted')">
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($accepted as $acceptance)
                    <details class="py-2 text-sm">
                        <summary class="cursor-pointer">
                            <span class="font-medium">{{ $acceptance->template?->title }}</span>
                            <span class="text-gray-500 dark:text-gray-400">· {{ __('Version :version', ['version' => $acceptance->template?->version]) }} · {{ __('Accepted on :date', ['date' => $acceptance->accepted_at->format('d M Y, h:i A')]) }}</span>
                        </summary>
                        <div class="mt-2 whitespace-pre-line">{{ $acceptance->template?->body }}</div>
                    </details>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
