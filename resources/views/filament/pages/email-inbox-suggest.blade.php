{{--
    The address suggestions under a To / Cc / Bcc box of the compose window (SuggestsEmailAddresses): the people this
    mailbox has written with and the company's customers that fit what is being typed. $field is to, cc or bcc.
    The list keeps the focus in the box (mousedown is held back) and closes on Escape or when focus leaves the box.
--}}
@if ($this->suggestField === $field && ($suggestions = $this->addressSuggestions) !== [])
    <ul class="zz-mail-suggest" role="listbox" aria-label="{{ __('Suggested addresses') }}" x-show="open" x-on:mousedown.prevent>
        @foreach ($suggestions as $index => $one)
            <li role="option" wire:key="suggest-{{ $field }}-{{ $index }}">
                <button type="button" wire:click="pickSuggestion({{ $index }})">
                    <b>{{ $one['name'] !== '' ? $one['name'] : $one['address'] }}</b>
                    @if ($one['name'] !== '')<span>{{ $one['address'] }}</span>@endif
                    @if ($one['customer'])<em>{{ __('Customer') }}</em>@endif
                </button>
            </li>
        @endforeach
    </ul>
@endif
