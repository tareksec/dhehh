<div class="space-y-3">
    @if (filled($notice))
        <div class="rounded-lg bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
            {{ $notice }}
        </div>
    @endif

    {{ $this->table }}
</div>
