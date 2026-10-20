@php use App\Support\ImsNumbers; @endphp

<x-filament-panels::page>
    @php
        $mine = $this->mine();
        $currency = $this->currency();
        $statusLabels = ['open' => __('Raising money'), 'running' => __('Running'), 'closed' => __('Finished, profit being worked out'), 'settled' => __('Settled')];
    @endphp

    <section class="flex flex-col gap-3">
        <h2 class="text-lg font-bold">{{ __('My projects') }}</h2>

        @if ($mine->isEmpty())
            <div class="ims-card">
                <p class="text-sm text-gray-600">{{ __('You have no project yet. See the groups raising money below.') }}</p>
            </div>
        @else
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(18rem,100%),1fr))]">
                @foreach ($mine as $project)
                    <a href="{{ \App\Filament\Ims\Portal\Pages\ProjectDetail::getUrl(['project' => $project->getKey()]) }}" class="ims-card flex flex-col gap-2" data-ims-project="{{ $project->getKey() }}">
                        <span class="text-sm text-gray-600">{{ $project->project_code }}</span>
                        <span class="font-semibold">{{ $project->name }}</span>
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

        @php $groups = $this->openGroups(); $open = $this->openProjects(); @endphp

        @if ($groups->isEmpty() && $open->isEmpty())
            <div class="ims-card"><p class="text-sm text-gray-600">{{ __('Nothing is raising money right now.') }}</p></div>
        @else
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(18rem,100%),1fr))]">
                @foreach ($groups as $group)
                    <div class="ims-card flex flex-col gap-1">
                        <span class="text-sm text-gray-600">{{ $group->group_code }}</span>
                        <span class="font-semibold">{{ $group->name }}</span>
                        <span class="text-sm">{{ __('Investors\' share of profit and loss: :percent', ['percent' => ImsNumbers::percent($group->investor_share_percent)]) }}</span>
                        <a class="text-sm text-primary-600 underline" href="{{ \App\Filament\Investor\Pages\Requests::getUrl() }}">{{ __('Ask to put money in') }}</a>
                    </div>
                @endforeach
                @foreach ($open as $project)
                    <a href="{{ \App\Filament\Ims\Portal\Pages\ProjectDetail::getUrl(['project' => $project->getKey()]) }}" class="ims-card flex flex-col gap-1">
                        <span class="text-sm text-gray-600">{{ $project->project_code }}</span>
                        <span class="font-semibold">{{ $project->name }}</span>
                        @if ($project->target_amount)
                            <span class="text-sm">{{ __('Target: :amount', ['amount' => ImsNumbers::money($project->target_amount, $currency)]) }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</x-filament-panels::page>
