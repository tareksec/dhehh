<x-filament-panels::page>
    @php
        $canEdit = $this->canEdit();
        $labels = $this->blockLabels();
    @endphp

    <div class="space-y-6">
        <x-filament::section
            icon="heroicon-o-sparkles"
            :heading="__('Instructions for the sales agent')"
            :description="__('Write how the AI should talk and when to hand a chat to the team. Try the text in the test chat below, then publish it.')"
        >
            <div class="space-y-5">
                @foreach ($labels as $key => $block)
                    <div wire:key="agent-block-{{ $key }}">
                        <label for="agent-block-{{ $key }}" class="text-sm font-medium">{{ $block['label'] }}</label>
                        <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ $block['hint'] }}</p>
                        <x-filament::input.wrapper :valid="! $errors->has('blocks.'.$key)">
                            <textarea
                                id="agent-block-{{ $key }}"
                                wire:model="blocks.{{ $key }}"
                                rows="{{ $block['rows'] }}"
                                maxlength="{{ $block['max'] }}"
                                @disabled(! $canEdit)
                                class="block w-full resize-y border-none bg-transparent px-3 py-2 text-sm outline-none focus:ring-0 dark:text-white"
                            ></textarea>
                        </x-filament::input.wrapper>
                        @error('blocks.'.$key)
                            <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>

            @if ($canEdit)
                <x-slot name="footer">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::button wire:click="saveDraft" color="gray" icon="heroicon-m-check">
                            {{ __('Save draft') }}
                        </x-filament::button>
                        @if ($hasDraft)
                            <x-filament::button wire:click="discardDraft" wire:confirm="{{ __('Discard the draft and go back to the live text?') }}" color="danger" outlined>
                                {{ __('Discard draft') }}
                            </x-filament::button>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('A draft is saved. It is not live until you publish.') }}</span>
                        @endif
                    </div>
                </x-slot>
            @else
                <x-slot name="footer">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Only people who can manage settings can change these instructions.') }}</p>
                </x-slot>
            @endif
        </x-filament::section>

        @if ($canEdit)
            <x-filament::section
                icon="heroicon-o-chat-bubble-left-right"
                :heading="__('Test chat')"
                :description="__('Write as a customer. Prices, stock and FAQ answers are read from your real catalog, but nothing is sent to anyone and no order, link or lead is created. Each test uses the AI provider you set on Integrations.')"
            >
                <div class="space-y-3">
                    @forelse ($transcript as $entry)
                        <div wire:key="test-entry-{{ $loop->index }}" @class([
                            'max-w-2xl rounded-lg px-3 py-2 text-sm',
                            'ms-auto bg-primary-50 dark:bg-primary-500/10' => $entry['kind'] === 'customer',
                            'bg-gray-100 dark:bg-white/5' => $entry['kind'] === 'ai',
                            'bg-warning-50 dark:bg-warning-500/10' => in_array($entry['kind'], ['handover', 'note'], true),
                        ])>
                            @if ($entry['kind'] === 'customer')
                                <p class="mb-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Customer') }}</p>
                                <p class="whitespace-pre-line">{{ $entry['text'] }}</p>
                            @elseif ($entry['kind'] === 'ai')
                                <p class="mb-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('AI reply') }}@if (! empty($entry['source'])) — {{ $this->sourceLabel($entry['source']) }}@endif</p>
                                <p class="whitespace-pre-line">{{ $entry['text'] }}</p>
                            @elseif ($entry['kind'] === 'handover')
                                <p class="mb-0.5 text-xs font-medium text-warning-700 dark:text-warning-400">{{ __('The AI would hand this chat to the team') }}@if (! empty($entry['code'])) — {{ $this->handoverLabel($entry['code']) }}@endif</p>
                                <p>{{ $entry['text'] }}@if (! empty($entry['source'])) <span class="text-xs text-gray-500 dark:text-gray-400">({{ $this->sourceLabel($entry['source']) }})</span>@endif</p>
                            @else
                                <p>{{ $entry['text'] }}</p>
                            @endif

                            @if (! empty($entry['tools']))
                                <details class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    <summary class="cursor-pointer">{{ __('What the AI looked up') }}</summary>
                                    <ul class="mt-1 space-y-1">
                                        @foreach ($entry['tools'] as $tool)
                                            <li><span class="font-medium">{{ $tool['tool'] }}</span> {{ $tool['input'] }} → {{ $tool['summary'] }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                            @if (! empty($entry['tokens']))
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __(':count tokens', ['count' => number_format($entry['tokens'])]) }}@if (! empty($entry['cost'])) · ${{ number_format($entry['cost'], 4) }}@endif
                                </p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No test messages yet.') }}</p>
                    @endforelse
                </div>

                <form wire:submit.prevent="sendTest" class="mt-4 space-y-3">
                    <x-filament::input.wrapper>
                        <x-filament::input type="text" wire:model="testMessage" maxlength="2000" placeholder="{{ __('Write as a customer, for example: What is the price of this lamp?') }}" />
                    </x-filament::input.wrapper>
                    <div class="flex flex-wrap items-center gap-4">
                        <x-filament::button type="submit" icon="heroicon-m-paper-airplane" wire:loading.attr="disabled" wire:target="sendTest">
                            <span wire:loading.remove wire:target="sendTest">{{ __('Send test message') }}</span>
                            <span wire:loading wire:target="sendTest">{{ __('The AI is working…') }}</span>
                        </x-filament::button>
                        @if ($transcript !== [])
                            <x-filament::button type="button" color="gray" wire:click="clearTest">{{ __('Clear test chat') }}</x-filament::button>
                        @endif
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="useEditorText" class="rounded border-gray-300 text-primary-600 dark:border-white/20">
                            {{ __('Use the text above, even if it is not published yet') }}
                        </label>
                    </div>
                </form>
            </x-filament::section>
        @endif

        <x-filament::section icon="heroicon-o-clock" :heading="__('Versions')" :description="__('Every change to these instructions, including changes saved on Integrations. Make an earlier version live again at any time.')">
            @php($versions = $this->versions())
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($versions as $version)
                    <div wire:key="version-{{ $version->id }}" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                        <div>
                            <p class="font-medium">
                                {{ __('Version :number', ['number' => $version->id]) }}
                                @if ($version->status === 'published')
                                    <x-filament::badge color="success" size="sm" class="ms-1 inline-flex">{{ __('Live') }}</x-filament::badge>
                                @endif
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $version->published_at?->format('d M Y, H:i') }}
                                · {{ __(\App\Models\AiInstructionVersion::SOURCES[$version->source] ?? $version->source) }}
                                @if ($version->publisher) · {{ $version->publisher->name }} @endif
                                @if ($version->change_note) · {{ $version->change_note }} @endif
                            </p>
                        </div>
                        @if ($canEdit)
                            <div class="flex gap-2">
                                <x-filament::button size="xs" color="gray" wire:click="loadVersion({{ $version->id }})">{{ __('Open in editor') }}</x-filament::button>
                                @if ($version->status !== 'published')
                                    <x-filament::button size="xs" wire:click="restoreVersion({{ $version->id }})" wire:confirm="{{ __('Make this version live again?') }}">{{ __('Make live again') }}</x-filament::button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
