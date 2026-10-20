<x-filament-panels::page>
    @php $items = $this->items(); @endphp

    @if ($items->isNotEmpty() && ! $this->isStaffPreview())
        <div>
            <x-filament::button wire:click="markAllRead" color="gray" size="sm" icon="heroicon-o-check">{{ __('Mark all read') }}</x-filament::button>
        </div>
    @endif

    <x-filament::section>
        @forelse ($items as $notification)
            @php $data = $notification->data; @endphp
            <div class="ims-todo-item" data-ims-notification="{{ $data['event'] ?? '' }}">
                <span class="flex min-w-0 items-start gap-3">
                    @if ($notification->read_at === null)
                        <span class="ims-status-dot mt-2 shrink-0 text-primary-600" aria-label="{{ __('New') }}"></span>
                    @endif
                    <span class="min-w-0">
                        <span class="block font-semibold">{{ $data['title'] ?? '' }}</span>
                        <span class="block text-sm">{{ $data['body'] ?? '' }}</span>
                        <span class="block text-xs text-gray-600">{{ \App\Support\ImsNumbers::date($notification->created_at) }}</span>
                    </span>
                </span>
                @if (! empty($data['url']))
                    <x-filament::link :href="$data['url']">{{ __('Open') }}</x-filament::link>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-600">{{ __('No news yet. When something happens with your money, it shows here.') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
