@php
    $user = auth()->user();
    $switcher = \App\Support\CompanySwitcher::for($user);

    if ($switcher) {
        $companies = $switcher['companies'];
        $selectedCompany = $switcher['selected'];
        $selectedCompanyName = $switcher['selectedName'];
    }
@endphp

@if ($switcher)
    <div class="zz-company-switcher">
        <x-filament::dropdown
            placement="bottom-start"
            width="xs"
            teleport
        >
            <x-slot name="trigger">
                <x-filament::input.wrapper
                    :suffix-icon="\Filament\Support\Icons\Heroicon::ChevronDown"
                    inline-suffix
                >
                    <button
                        type="button"
                        aria-label="Current company"
                        class="fi-input fi-input-has-inline-suffix zz-company-switcher-trigger"
                    >
                        <span class="zz-company-switcher-trigger-label">
                            {{ $selectedCompanyName }}
                        </span>
                    </button>
                </x-filament::input.wrapper>
            </x-slot>

            <x-filament::dropdown.list>
                @if ($user->isSuperAdmin())
                    <form method="POST" action="{{ route('admin.company.switch') }}">
                        @csrf
                        <input type="hidden" name="company_id" value="all">

                        <x-filament::dropdown.list.item
                            type="submit"
                            :color="$selectedCompany === 'all' ? 'primary' : 'gray'"
                            class="{{ $selectedCompany === 'all' ? 'fi-selected' : '' }}"
                        >
                            All Companies
                        </x-filament::dropdown.list.item>
                    </form>
                @endif

                @foreach ($companies as $company)
                    <form method="POST" action="{{ route('admin.company.switch') }}">
                        @csrf
                        <input type="hidden" name="company_id" value="{{ $company->getKey() }}">

                        <x-filament::dropdown.list.item
                            type="submit"
                            :color="(string) $selectedCompany === (string) $company->getKey() ? 'primary' : 'gray'"
                            class="{{ (string) $selectedCompany === (string) $company->getKey() ? 'fi-selected' : '' }}"
                        >
                            {{ $company->name }}
                        </x-filament::dropdown.list.item>
                    </form>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    </div>
@endif
