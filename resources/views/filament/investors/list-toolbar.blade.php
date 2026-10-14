{{--
    Project picker on the left of the investor list's table toolbar. It writes
    into the table's own "Project" filter, so the filter badge, the chips and
    "Reset filters" follow it.
--}}
<div
    wire:key="investor-list-toolbar"
    x-data="{
        selectedProject() {
            const values = $wire.tableFilters?.project?.values ?? []

            if (values.length === 0) {
                return ''
            }

            return values.length > 1 ? 'many' : String(values[0])
        },
    }"
    class="flex w-full flex-wrap items-center gap-3 sm:w-auto"
>
    <label class="w-full sm:w-96">
        <span class="fi-sr-only">{{ __('Filter by project') }}</span>

        <x-filament::input.wrapper wire:target="setProjectFilter">
            <x-filament::input.select
                x-bind:value="selectedProject()"
                x-on:change="if ($event.target.value !== 'many') { $wire.setProjectFilter($event.target.value) }"
            >
                <option value="">{{ __('All projects') }}</option>

                @foreach ($projects as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach

                <option value="many" hidden>{{ __('Several projects') }}</option>
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </label>
</div>
