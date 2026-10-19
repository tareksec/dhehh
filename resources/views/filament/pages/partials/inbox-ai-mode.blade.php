@php
    /** @var \App\Models\Conversation $conversation */
    $isHuman = $conversation->isHumanMode();
    $revertAt = $conversation->revertAt()?->copy()->timezone($conversationTimezone ?? config('app.timezone'));
    $reasonLabel = $conversation->handover_reason
        ? (\App\Models\Conversation::HANDOVER_REASONS[$conversation->handover_reason] ?? null)
        : null;
@endphp

<div class="space-y-3" wire:key="ai-mode-{{ $keyPrefix }}-{{ $conversation->getKey() }}">
    <div class="flex flex-wrap items-center gap-2">
        @if ($isHuman)
            <x-filament::badge color="warning" icon="heroicon-m-user">{{ __('With the team') }}</x-filament::badge>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ $revertAt ? __('Back to AI at :time', ['time' => $revertAt->format('d M, h:i A')]) : __('No time limit') }}
            </span>
        @else
            <x-filament::badge color="success" icon="heroicon-m-sparkles">{{ __('AI is handling this chat') }}</x-filament::badge>
        @endif
    </div>

    @if ($isHuman && $reasonLabel)
        <p class="text-xs text-gray-600 dark:text-gray-300">{{ __('Reason: :reason', ['reason' => __($reasonLabel)]) }}</p>
    @endif

    @if ($conversation->assignedUser)
        <p class="text-xs text-gray-600 dark:text-gray-300">{{ __('Handled by :name', ['name' => $conversation->assignedUser->name]) }}</p>
    @endif

    <div class="flex flex-wrap gap-2">
        @if ($isHuman)
            <x-filament::button size="sm" color="success" icon="heroicon-m-sparkles" wire:click="backToAi">
                {{ __('Back to AI') }}
            </x-filament::button>
            @if ((int) $conversation->assigned_to !== (int) auth()->id())
                <x-filament::button size="sm" color="gray" outlined wire:click="claimConversation">
                    {{ __('Claim') }}
                </x-filament::button>
            @endif
        @else
            <x-filament::button size="sm" color="warning" icon="heroicon-m-hand-raised" wire:click="takeOver">
                {{ __('Take over') }}
            </x-filament::button>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-1">
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Keep with the team for:') }}</span>
        <x-filament::button size="xs" color="gray" outlined wire:click="takeOver(30)">{{ __('30 min') }}</x-filament::button>
        <x-filament::button size="xs" color="gray" outlined wire:click="takeOver(60)">{{ __('1 hour') }}</x-filament::button>
        <x-filament::button size="xs" color="gray" outlined wire:click="takeOver(360)">{{ __('6 hours') }}</x-filament::button>
        <x-filament::button size="xs" color="gray" outlined wire:click="takeOver(null, true)">{{ __('No time limit') }}</x-filament::button>
    </div>
</div>
