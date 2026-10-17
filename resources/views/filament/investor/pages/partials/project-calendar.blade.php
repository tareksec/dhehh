{{--
    This year's projects of the person's groups, one box per project in date
    order (InvestorPortalCards::calendar()). $tile is the dashboard's tile class.
--}}
@php
    $states = [
        'paid' => [__('Paid'), 'bg-[#f1f3f6] text-[#3a414d] dark:bg-white/5 dark:text-gray-300'],
        'confirmed' => [__('Profit confirmed'), 'bg-[#fff1d6] text-[#7a4a06] dark:bg-[#c98a1b]/20 dark:text-[#ffd894]'],
        'completed' => [__('Completed'), 'bg-[#e3f1e7] text-[#1d5b33] dark:bg-[#9bd35a]/15 dark:text-[#cdeaa9]'],
        'running' => [__('Running'), 'bg-(--portal-ink) text-white dark:ring-1 dark:ring-(--portal-accent)'],
        'upcoming' => [__('Upcoming'), 'border-[1.5px] border-dashed border-[#c7cdd6] text-[#4a5260] dark:border-white/20 dark:text-gray-400'],
    ];
    $period = function ($starts, $ends): string {
        if ($ends === null) {
            return $starts->translatedFormat('d M');
        }

        // A calendar-month cycle reads as its months ("Sep – Oct"), anything else by its dates.
        return $starts->day === 1 && $ends->isLastOfMonth()
            ? ($starts->isSameMonth($ends) ? $starts->translatedFormat('M') : $starts->translatedFormat('M').' – '.$ends->translatedFormat('M'))
            : $starts->translatedFormat('d M').' – '.$ends->translatedFormat('d M');
    };
@endphp

<section aria-labelledby="investor-calendar" class="{{ $tile }} flex flex-[2_1_34rem] flex-col gap-5" data-investor-calendar>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="investor-calendar" class="text-lg font-bold">{{ __('Project calendar :year', ['year' => now()->year]) }}</h2>
        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600 dark:text-gray-400" aria-label="{{ __('Legend') }}">
            @foreach ($states as $key => [$name, $classes])
                <li class="flex items-center gap-1.5">
                    <span class="size-3 rounded {{ $classes }}" aria-hidden="true"></span>{{ $name }}
                </li>
            @endforeach
        </ul>
    </div>

    @foreach ($calendar as $row)
        <div class="flex flex-col gap-2">
            @if ($calendar->count() > 1)
                <div class="text-sm font-semibold">{{ $row['group']->group_code }} — {{ $row['group']->name }}</div>
            @endif
            <ol class="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(min(8.5rem,100%),1fr))]">
                @foreach ($row['entries'] as $entry)
                    @php [$name, $classes] = $states[$entry['state']]; @endphp
                    <li
                        class="flex min-h-24 flex-col gap-1 rounded-2xl px-3 py-3 {{ $classes }}"
                        data-calendar-state="{{ $entry['state'] }}"
                        @if ($entry['state'] === 'running') aria-current="step" @endif
                    >
                        <span class="text-sm opacity-90">{{ $period($entry['starts_on'], $entry['ends_on']) }}</span>
                        <span class="font-bold">{{ $entry['number'] ? __('Project :number', ['number' => $entry['number']]) : '-' }}</span>
                        <span class="flex items-center gap-1.5 text-sm">
                            @if ($entry['state'] === 'running')
                                <span class="rounded-md bg-(--portal-accent) px-1.5 text-xs font-bold text-(--portal-ink)">{{ __('Now') }}</span>
                            @elseif ($entry['state'] === 'paid')
                                <x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />{{ $name }}
                            @else
                                {{ $name }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endforeach
</section>
