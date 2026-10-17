{{-- "Save to Google Drive" state for one GeneratedVideo ($video). The host page provides the saveToDrive action and driveAvailable(). --}}
@if ($video->isSavedToDrive())
    <x-filament::button
        tag="a"
        href="{{ $video->drive_url }}"
        target="_blank"
        rel="noopener"
        size="xs"
        color="success"
        icon="heroicon-o-arrow-top-right-on-square"
    >
        {{ __('Open in Google Drive') }}
    </x-filament::button>
@elseif ($video->isSavingToDrive())
    <span class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
        <x-filament::loading-indicator class="h-3 w-3" />
        {{ __('Saving to Google Drive…') }}
    </span>
@elseif ($this->driveAvailable())
    <x-filament::button
        size="xs"
        color="gray"
        icon="heroicon-o-cloud-arrow-up"
        wire:click="mountAction('saveToDrive', { generation: {{ $video->id }} })"
        :tooltip="$video->drive_status === \App\Models\GeneratedVideo::DRIVE_FAILED ? __('Last try failed: :error', ['error' => \Illuminate\Support\Str::limit((string) $video->drive_error, 120)]) : null"
    >
        {{ $video->drive_status === \App\Models\GeneratedVideo::DRIVE_FAILED ? __('Retry Google Drive') : __('Save to Google Drive') }}
    </x-filament::button>
@endif
