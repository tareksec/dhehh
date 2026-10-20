<x-filament-panels::page>
    @php $current = $this->current(); @endphp

    @if ($current)
        <div>
            <x-filament::link wire:click="$set('thread', null)" tag="button" icon="heroicon-m-arrow-left">{{ __('All questions') }}</x-filament::link>
        </div>

        <x-filament::section :heading="$current->subject">
            <ul class="flex flex-col gap-3" data-ims-thread="{{ $current->getKey() }}">
                @foreach ($current->messages as $message)
                    <li @class(['max-w-[85%] rounded-2xl px-4 py-2 text-sm', 'self-end bg-primary-50' => ! $message->from_staff, 'self-start bg-gray-100' => $message->from_staff])>
                        <span class="block text-xs font-semibold text-gray-600">{{ $message->from_staff ? __('Office') : __('You') }} · {{ \App\Support\ImsNumbers::date($message->created_at) }}</span>
                        <span class="whitespace-pre-line break-words">{{ $message->body }}</span>
                    </li>
                @endforeach
            </ul>

            @unless ($this->isStaffPreview())
                <form wire:submit="sendReply" class="mt-4 flex flex-col gap-2">
                    <x-filament::input.wrapper>
                        <textarea wire:model="reply" rows="3" maxlength="3000" class="block w-full border-none bg-transparent px-3 py-2 text-sm" placeholder="{{ $current->isOpen() ? __('Write your answer') : __('Write to open this question again') }}"></textarea>
                    </x-filament::input.wrapper>
                    <div><x-filament::button type="submit">{{ __('Send') }}</x-filament::button></div>
                </form>
            @endunless
        </x-filament::section>
    @else
        @unless ($this->isStaffPreview())
            <x-filament::section :heading="__('Ask the office')">
                <form wire:submit="ask" class="flex flex-col gap-3">
                    <x-filament::input.wrapper>
                        <x-filament::input type="text" wire:model="subject" maxlength="255" :placeholder="__('Subject')" />
                    </x-filament::input.wrapper>
                    @if ($this->projectOptions() !== [])
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="projectId">
                                <option value="">{{ __('About a project (optional)') }}</option>
                                @foreach ($this->projectOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @endif
                    <x-filament::input.wrapper>
                        <textarea wire:model="body" rows="4" maxlength="3000" class="block w-full border-none bg-transparent px-3 py-2 text-sm" placeholder="{{ __('Your question') }}"></textarea>
                    </x-filament::input.wrapper>
                    <div><x-filament::button type="submit" size="lg">{{ __('Send') }}</x-filament::button></div>
                </form>
            </x-filament::section>
        @endunless

        <x-filament::section :heading="__('My questions')">
            @forelse ($this->threads() as $thread)
                <button type="button" wire:click="$set('thread', {{ $thread->getKey() }})" class="ims-todo-item w-full text-start">
                    <span class="flex min-w-0 items-center gap-2">
                        @if ($thread->investor_unread)
                            <span class="ims-status-dot shrink-0 text-primary-600" aria-label="{{ __('New answer') }}"></span>
                        @endif
                        <span class="truncate font-semibold">{{ $thread->subject }}</span>
                    </span>
                    <x-filament::badge :color="$thread->isOpen() ? 'warning' : 'gray'">{{ $thread->isOpen() ? __('Open') : __('Closed') }}</x-filament::badge>
                </button>
            @empty
                <p class="text-sm text-gray-600">{{ __('No questions yet. Ask anything above.') }}</p>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>
