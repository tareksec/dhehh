<x-filament-panels::page>
    <x-filament::section>
        <div class="mb-3 flex flex-wrap gap-3 text-sm text-gray-600">
            <span>{{ __('Investor') }}: <strong>{{ $this->record->investor?->name }}</strong></span>
            @if ($this->record->project)
                <span>{{ __('Project') }}: {{ $this->record->project->name }}</span>
            @endif
        </div>
        <ul class="flex flex-col gap-3">
            @foreach ($this->record->messages()->with('user')->get() as $message)
                <li @class(['max-w-[85%] rounded-2xl px-4 py-2 text-sm', 'self-start bg-gray-100' => ! $message->from_staff, 'self-end bg-primary-50' => $message->from_staff])>
                    <span class="block text-xs font-semibold text-gray-600">
                        {{ $message->from_staff ? ($message->user?->name ?? __('Office')) : __('Investor') }} · {{ \App\Support\ImsNumbers::date($message->created_at) }}
                    </span>
                    <span class="whitespace-pre-line">{{ $message->body }}</span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-panels::page>
