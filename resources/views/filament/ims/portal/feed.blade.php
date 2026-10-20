<x-filament-panels::page>
    @php $posts = $this->posts(); @endphp

    @forelse ($posts as $post)
        <article class="ims-card flex flex-col gap-3" data-ims-post="{{ $post->getKey() }}">
            <header class="flex flex-wrap items-center gap-2">
                @if ($post->is_pinned)
                    <x-filament::badge color="success" icon="heroicon-m-bookmark">{{ __('Pinned') }}</x-filament::badge>
                @endif
                <span class="text-xs text-gray-600">{{ \App\Support\ImsNumbers::date($post->published_at) }}</span>
            </header>

            @if ($post->title)
                <h2 class="text-lg font-bold">{{ $post->title }}</h2>
            @endif
            <p class="whitespace-pre-line text-sm">{{ $post->body }}</p>

            @if ($post->link_url)
                <a class="text-sm text-primary-600 underline break-all" href="{{ $post->link_url }}" target="_blank" rel="noopener noreferrer">{{ $post->link_url }}</a>
            @endif

            @if ($post->images->isNotEmpty())
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($post->images as $image)
                        <img src="{{ route('filament.investor.ims.feed-image', ['image' => $image->getKey()]) }}" alt="" class="aspect-square w-full rounded-xl object-cover" loading="lazy">
                    @endforeach
                </div>
            @endif

            <div class="flex items-center gap-3">
                <x-filament::button size="sm" :color="$this->likedByMe($post) ? 'primary' : 'gray'" icon="heroicon-o-heart" wire:click="like({{ $post->getKey() }})" :disabled="$this->isStaffPreview()">
                    {{ __('Like') }} · {{ \App\Support\ImsNumbers::count($post->likes_count) }}
                </x-filament::button>
                <span class="text-sm text-gray-600">{{ __(':count comments', ['count' => \App\Support\ImsNumbers::count($post->comments->count())]) }}</span>
            </div>

            @if ($post->comments->isNotEmpty())
                <ul class="flex flex-col gap-2">
                    @foreach ($post->comments as $comment)
                        <li class="rounded-xl bg-gray-50 px-3 py-2 text-sm">
                            <span class="font-semibold">{{ $this->isMine($comment) ? __('You') : __('An investor') }}:</span>
                            {{ $comment->body }}
                            @if ($this->isMine($comment) && ! $this->isStaffPreview())
                                <button type="button" class="ms-2 text-xs text-danger-600 underline" wire:click="deleteComment({{ $comment->getKey() }})">{{ __('Delete') }}</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($post->comments_enabled && ! $this->isStaffPreview())
                <form wire:submit="comment({{ $post->getKey() }})" class="flex gap-2">
                    <x-filament::input.wrapper class="flex-1">
                        <x-filament::input type="text" wire:model="drafts.{{ $post->getKey() }}" maxlength="1000" :placeholder="__('Write a comment (others see it without your name)')" />
                    </x-filament::input.wrapper>
                    <x-filament::button type="submit" size="sm">{{ __('Send') }}</x-filament::button>
                </form>
            @endif
        </article>
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-600">{{ __('Nothing posted yet. Company news will show here.') }}</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
