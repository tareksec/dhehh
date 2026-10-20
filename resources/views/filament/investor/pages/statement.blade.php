<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-end gap-4">
            <label class="text-sm">
                <span class="mb-1 block text-gray-500 dark:text-gray-400">{{ __('From') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="from" />
                </x-filament::input.wrapper>
            </label>
            <label class="text-sm">
                <span class="mb-1 block text-gray-500 dark:text-gray-400">{{ __('Until') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="until" />
                </x-filament::input.wrapper>
            </label>
        </div>
    </x-filament::section>

    <x-filament::section>
        @include('filament.investor.pages.partials.statement-rows', ['entries' => $this->entries(), 'currency' => $this->currency()])
        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Profit and loss appear once the company confirms a project\'s settlement. A reinvested amount moves into a new investment, which is listed as its own entry.') }}
        </p>
    </x-filament::section>

    {{-- IMS v2 (PRD FR-65): a PDF statement for each month. --}}
    <x-filament::section :heading="__('Monthly statements (PDF)')">
        <div class="flex flex-wrap gap-2" data-ims-monthly-statements>
            @foreach (range(0, 11) as $back)
                @php $month = now()->startOfMonth()->subMonths($back); @endphp
                <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-arrow-down-tray"
                    :href="route('filament.investor.ims.statement-pdf', ['period' => $month->format('Y-m')])">
                    {{ \App\Support\ImsNumbers::digits($month->copy()->locale(app()->getLocale())->translatedFormat('M Y')) }}
                </x-filament::button>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
