<x-filament-widgets::widget class="ims-widget">
    @php $items = $this->items(); @endphp

    <section class="ims-card" aria-labelledby="ims-todo-heading">
        <div class="ims-card-head">
            <span class="ims-card-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-clipboard-document-check" class="h-5 w-5" /></span>
            <div class="min-w-0 flex-1">
                <h2 id="ims-todo-heading" class="ims-card-title">{{ __('What to do today') }}</h2>
                <p class="ims-kpi-note">{{ __('The most urgent first') }}</p>
            </div>
        </div>

        @if ($items === [])
            <div class="ims-empty">
                <x-filament::icon icon="heroicon-o-check-badge" class="h-10 w-10 text-(--ims-success)" />
                <p>{{ __('Nothing is waiting for you today.') }}</p>
            </div>
        @else
            <ul data-ims-todo class="ims-todo">
                @foreach ($items as $item)
                    <li class="ims-todo-item" data-ims-todo-item="{{ $item['key'] }}">
                        @if ($item['url'])
                            <a href="{{ $item['url'] }}" class="ims-todo-link">
                                <span @class(['ims-count', 'ims-count-'.$item['tone']])>{{ \App\Support\ImsNumbers::count($item['count']) }}</span>
                                <span class="ims-todo-label">{{ $item['label'] }}</span>
                                <x-filament::icon icon="heroicon-m-chevron-right" class="ims-todo-chevron" />
                            </a>
                        @else
                            <span class="ims-todo-link">
                                <span @class(['ims-count', 'ims-count-'.$item['tone']])>{{ \App\Support\ImsNumbers::count($item['count']) }}</span>
                                <span class="ims-todo-label">{{ $item['label'] }}</span>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-filament-widgets::widget>
