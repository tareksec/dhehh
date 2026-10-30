@php
    /** @var \App\Models\SocialPost|null $record */
    $record = $post ?? null;
    $results = (array) ($record?->publish_result ?? []);
    $labels = \App\Models\SocialPost::PLATFORMS;
    $colors = ['succeeded' => 'success', 'failed' => 'danger', 'uncertain' => 'warning'];
    $words = ['succeeded' => __('Posted'), 'failed' => __('Failed'), 'uncertain' => __('Needs checking')];
@endphp

<div class="space-y-3 text-sm">
    @if ($record?->publish_state === \App\Models\SocialPost::PUBLISH_QUEUED || $record?->publish_state === \App\Models\SocialPost::PUBLISH_RUNNING)
        <p class="text-gray-600 dark:text-gray-300">{{ __('This post is being published. Refresh in a minute.') }}</p>
    @endif

    @forelse ($results as $platform => $result)
        @php($status = (string) ($result['status'] ?? ''))
        <div class="flex flex-wrap items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="min-w-32 font-medium">{{ __($labels[$platform] ?? ucfirst($platform)) }}</div>
            <x-filament::badge :color="$colors[$status] ?? 'gray'">{{ $words[$status] ?? $status }}</x-filament::badge>
            <div class="flex-1 space-y-1">
                @if (! empty($result['link']))
                    <a href="{{ $result['link'] }}" target="_blank" rel="noopener" class="text-primary-600 underline dark:text-primary-400">{{ __('Open the post') }}</a>
                @endif
                @if (! empty($result['post_id']))
                    <div class="text-gray-500 dark:text-gray-400">{{ __('Platform post id') }}: {{ $result['post_id'] }}</div>
                @endif
                @if (! empty($result['error']))
                    <div class="text-danger-600 dark:text-danger-400">{{ $result['error'] }}</div>
                @endif
            </div>
        </div>
    @empty
        @if (! in_array($record?->publish_state, [\App\Models\SocialPost::PUBLISH_QUEUED, \App\Models\SocialPost::PUBLISH_RUNNING], true))
            <p class="text-gray-500 dark:text-gray-400">{{ __('Nothing has been published yet.') }}</p>
        @endif
    @endforelse

    @if ($record?->publish_error)
        <p class="text-danger-600 dark:text-danger-400">{{ $record->publish_error }}</p>
    @endif
</div>
