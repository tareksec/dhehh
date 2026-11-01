{{--
    The ⓘ icon for instruction text (CLAUDE.md "Instruction text goes in an ⓘ icon").
    Hover (or tap on a touch screen) shows the text; the button is focusable, so
    keyboard and screen-reader users get it too. Built from Filament's own
    icon-button, so it matches the ⓘ on the form fields.

    <x-info-tip :text="__('English text')" />
--}}
@props(['text' => null])

@if (filled($text))
    <x-filament::icon-button
        icon="heroicon-o-information-circle"
        color="gray"
        size="sm"
        :tooltip="$text"
        :label="$text"
        :loading-indicator="false"
        {{ $attributes->class(['fi-info-tip']) }}
    />
@endif
