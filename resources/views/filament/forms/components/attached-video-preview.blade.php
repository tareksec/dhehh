<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @if ($url = $getVideoUrl())
        <video
            class="aspect-video w-full max-w-md rounded-lg border border-gray-200 bg-black object-contain dark:border-white/10"
            src="{{ $url }}"
            controls
            preload="metadata"
        ></video>
    @endif
</x-dynamic-component>
