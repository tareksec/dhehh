<x-filament-panels::page>
    <form wire:submit="save" class="flex flex-col gap-6">
        <x-filament::section :description="__('The people who receive your money if something happens to you. With more than one, their shares must add up to 100%.')">
            {{ $this->form }}
        </x-filament::section>

        @unless ($this->isStaffPreview())
            <div>
                <x-filament::button type="submit" size="lg">{{ __('Save nominees') }}</x-filament::button>
            </div>
        @endunless
    </form>
</x-filament-panels::page>
