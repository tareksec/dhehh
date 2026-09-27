<x-filament-panels::page>
    @if (! $this->hasSelectedCompany())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Select a single company from the switcher above to use Video Generation.
            </p>
        </x-filament::section>
    @else
        <x-filament::section
            icon="heroicon-o-film"
            heading="New video"
            description="Pick a starting image and describe the motion you want. Generation runs in the background and can take a few minutes; results appear below."
        >
            <form wire:submit="generate" id="video-generation-form" class="space-y-6">
                {{ $this->form }}
            </form>
        </x-filament::section>

        <div
            class="space-y-4"
            @if ($this->hasPendingGenerations()) wire:poll.5s @endif
        >
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Your recent generations</h3>

            @forelse ($this->recentGenerations() as $generation)
                <x-filament::section>
                    <div class="flex flex-col gap-3">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0 space-y-1">
                                <p class="text-sm text-gray-950 dark:text-white">
                                    {{ \Illuminate\Support\Str::limit($generation->prompt, 160) ?: '—' }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $generation->contextLabel() }}
                                    &middot; {{ $generation->aspect_ratio }}
                                    &middot; {{ $generation->durationLabel() }}
                                    &middot; {{ $generation->provider_label ?: $generation->api_format }}
                                    &middot; {{ $generation->created_at?->diffForHumans() }}
                                </p>
                            </div>

                            @php($status = $generation->status)
                            <x-filament::badge :color="match ($status) {
                                \App\Models\GeneratedVideo::STATUS_COMPLETED => 'success',
                                \App\Models\GeneratedVideo::STATUS_FAILED => 'danger',
                                default => 'warning',
                            }">
                                {{ \Illuminate\Support\Str::headline($status) }}
                            </x-filament::badge>
                        </div>

                        @if ($status === \App\Models\GeneratedVideo::STATUS_FAILED)
                            <p class="text-sm text-danger-600 dark:text-danger-400">
                                {{ $generation->error_message ?: 'The video could not be generated.' }}
                            </p>
                        @elseif ($status !== \App\Models\GeneratedVideo::STATUS_COMPLETED)
                            <p class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                                <x-filament::loading-indicator class="h-4 w-4" />
                                @if ($generation->isPolling())
                                    Checking on your video… (check {{ $generation->poll_attempts }})
                                @else
                                    Starting your video…
                                @endif
                            </p>
                        @else
                            <div class="max-w-md">
                                <video
                                    controls
                                    preload="metadata"
                                    @if ($generation->thumbnailUrl()) poster="{{ $generation->thumbnailUrl() }}" @endif
                                    class="w-full rounded-lg border border-gray-200 dark:border-white/10"
                                >
                                    <source src="{{ $generation->outputUrl() }}" type="{{ $generation->video_mime_type ?: 'video/mp4' }}" />
                                </video>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::button
                                    tag="a"
                                    href="{{ $generation->outputUrl() }}"
                                    download
                                    size="xs"
                                    color="gray"
                                    icon="heroicon-o-arrow-down-tray"
                                >
                                    Download
                                </x-filament::button>
                            </div>
                        @endif
                    </div>
                </x-filament::section>
            @empty
                <x-filament::section>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No generations yet. Pick a starting image, describe the motion, and press Generate.
                    </p>
                </x-filament::section>
            @endforelse
        </div>
    @endif
</x-filament-panels::page>
