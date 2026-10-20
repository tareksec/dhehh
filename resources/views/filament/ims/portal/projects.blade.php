@php use App\Support\ImsNumbers; @endphp

{{--
    "My projects" and "Raising money now" from every company the person
    invests in (18_IMS_ALL_COMPANIES_PLAN.md §4). With more than one company,
    each card names its company in plain text.
--}}
<x-filament-panels::page>
    @php
        $mine = $this->mine();
        $raising = $this->raising();
        $named = $this->showsCompanies();
        $statusLabels = ['open' => __('Raising money'), 'running' => __('Running'), 'closed' => __('Finished, profit being worked out'), 'settled' => __('Settled')];
    @endphp

    <section class="flex flex-col gap-3">
        <h2 class="text-lg font-bold">{{ __('My projects') }}</h2>

        @if ($mine === [])
            <div class="ims-card">
                <p class="text-sm text-gray-600">{{ __('You have no project yet. See the groups raising money below.') }}</p>
            </div>
        @else
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(18rem,100%),1fr))]">
                @foreach ($mine as $row)
                    @php $project = $row['project']; @endphp
                    <a href="{{ $row['url'] }}" class="ims-card flex flex-col gap-2" data-ims-project="{{ $project->getKey() }}">
                        <span class="text-sm text-gray-600">{{ $project->project_code }}</span>
                        <span class="font-semibold">{{ $project->name }}</span>
                        @if ($named)
                            <span class="ims-portal-company" data-ims-project-company>
                                <x-filament::icon icon="heroicon-o-building-office-2" class="h-4 w-4" />
                                {{ $row['company_name'] }}
                            </span>
                        @endif
                        <span class="flex items-center gap-2 text-sm">
                            <span class="ims-status-dot text-primary-600"></span>
                            {{ $statusLabels[$project->status] ?? $project->status }}
                        </span>
                        @if ($project->start_date && $project->end_date)
                            @php
                                $total = max(1, $project->start_date->diffInDays($project->end_date));
                                $done = min($total, max(0, $project->start_date->diffInDays(now(), false)));
                                $percent = (int) round(100 * $done / $total);
                            @endphp
                            <span class="ims-progress" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $percent }}%"></span></span>
                            <span class="text-xs text-gray-600">{{ ImsNumbers::date($project->start_date) }} → {{ ImsNumbers::date($project->end_date) }} · {{ ImsNumbers::percent($percent) }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <section class="flex flex-col gap-3">
        <h2 class="text-lg font-bold">{{ __('Raising money now') }}</h2>

        @if ($raising['groups'] === [] && $raising['projects'] === [])
            <div class="ims-card"><p class="text-sm text-gray-600">{{ __('Nothing is raising money right now.') }}</p></div>
        @else
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(18rem,100%),1fr))]">
                @foreach ($raising['groups'] as $row)
                    <div class="ims-card flex flex-col gap-1">
                        <span class="text-sm text-gray-600">{{ $row['group']->group_code }}</span>
                        <span class="font-semibold">{{ $row['group']->name }}</span>
                        @if ($named)
                            <span class="ims-portal-company">
                                <x-filament::icon icon="heroicon-o-building-office-2" class="h-4 w-4" />
                                {{ $row['company_name'] }}
                            </span>
                        @endif
                        <span class="text-sm">{{ __('Investors\' share of profit and loss: :percent', ['percent' => ImsNumbers::percent($row['group']->investor_share_percent)]) }}</span>
                        @if ($row['url'])
                            <a class="text-sm text-primary-600 underline" href="{{ $row['url'] }}">{{ __('Ask to put money in') }}</a>
                        @endif
                    </div>
                @endforeach
                @foreach ($raising['projects'] as $row)
                    <a href="{{ $row['url'] }}" class="ims-card flex flex-col gap-1">
                        <span class="text-sm text-gray-600">{{ $row['project']->project_code }}</span>
                        <span class="font-semibold">{{ $row['project']->name }}</span>
                        @if ($named)
                            <span class="ims-portal-company">
                                <x-filament::icon icon="heroicon-o-building-office-2" class="h-4 w-4" />
                                {{ $row['company_name'] }}
                            </span>
                        @endif
                        @if ($row['project']->target_amount)
                            <span class="text-sm">{{ __('Target: :amount', ['amount' => ImsNumbers::money($row['project']->target_amount, $row['currency'])]) }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</x-filament-panels::page>
