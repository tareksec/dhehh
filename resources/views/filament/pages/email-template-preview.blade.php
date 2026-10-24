@if ($preview)
    <div class="space-y-3">
        <p class="text-sm">
            <span class="text-gray-500 dark:text-gray-400">{{ __('Subject') }}:</span>
            <span class="font-semibold">{{ $preview['subject'] }}</span>
        </p>
        <iframe
            title="{{ __('Preview') }}"
            sandbox=""
            srcdoc="{{ $preview['html'] }}"
            class="h-[28rem] w-full rounded-lg border border-gray-200 bg-white dark:border-white/10"
        ></iframe>
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Write the message first, then preview it.') }}</p>
@endif
