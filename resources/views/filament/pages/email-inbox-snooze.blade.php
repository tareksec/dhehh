{{--
    The Snooze button of the Email page: a drop-down of ready-made times and a "Pick date and time" box. $target is
    "thread" (the open conversation) or "selected" (the ticked ones). The times are the company's local time
    (SnoozesEmail::snoozeChoices()); the conversation leaves the Inbox and comes back unread at that time.
--}}
@php
    $method = $target === 'thread' ? 'snoozeThread' : 'snoozeSelected';
@endphp
<x-filament::dropdown placement="bottom-start" width="xs">
    <x-slot name="trigger">
        <button type="button" class="zz-mail-ib" aria-label="{{ __('Snooze') }}" title="{{ __('Snooze') }}"><x-filament::icon icon="heroicon-o-clock" class="zz-mail-i" aria-hidden="true" /></button>
    </x-slot>
    <x-filament::dropdown.list>
        @foreach ($this->snoozeChoices() as $key => [$name, $at])
            <x-filament::dropdown.list.item wire:click="{{ $method }}('{{ $key }}')" wire:key="snooze-{{ $target }}-{{ $key }}">
                {{ $name }} <span class="zz-mail-when">{{ $at->translatedFormat('D, g:i A') }}</span>
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
    <div class="zz-mail-snooze-custom">
        <label for="snooze-custom-{{ $target }}">{{ __('Pick date and time') }}</label>
        <input id="snooze-custom-{{ $target }}" type="datetime-local" wire:model="snoozeCustom" min="{{ $this->snoozeMin() }}">
        <button type="button" class="zz-mail-btn zz-mail-btn-quiet" wire:click="{{ $method }}('custom')">{{ __('Snooze') }}</button>
    </div>
</x-filament::dropdown>
