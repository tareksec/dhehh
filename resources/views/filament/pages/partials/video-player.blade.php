{{-- Plays one finished GeneratedVideo ($video) from the company's own storage. --}}
<video
    controls
    autoplay
    playsinline
    preload="metadata"
    @if ($video->thumbnailUrl()) poster="{{ $video->thumbnailUrl() }}" @endif
    class="w-full rounded-lg border border-gray-200 bg-black dark:border-white/10"
>
    <source src="{{ $video->outputUrl() }}" type="{{ $video->video_mime_type ?: 'video/mp4' }}" />
</video>
