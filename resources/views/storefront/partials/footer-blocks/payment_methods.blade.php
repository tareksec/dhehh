@php
    $paymentTones = [
        'pink' => 'text-pink-600 dark:text-pink-400',
        'orange' => 'text-orange-600 dark:text-orange-400',
        'purple' => 'text-purple-600 dark:text-purple-400',
        'blue' => 'text-blue-600 dark:text-blue-400',
        'green' => 'text-emerald-600 dark:text-emerald-400',
        'red' => 'text-red-600 dark:text-red-400',
    ];
    $paymentBadges = collect($data['items'] ?? [])->filter(fn ($item) => filled($item['label'] ?? null));
@endphp
@if ($paymentBadges->isNotEmpty())
    <div>
        @if (filled($data['heading'] ?? null))
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $data['heading'] }}</div>
        @endif
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($paymentBadges as $badge)
                <span class="inline-flex items-center rounded-md border border-gray-200 px-2.5 py-1 text-xs font-bold dark:border-white/10 {{ $paymentTones[$badge['tone'] ?? ''] ?? 'text-gray-700 dark:text-gray-300' }}">{{ $badge['label'] }}</span>
            @endforeach
        </div>
    </div>
@endif
