<x-filament-panels::page>
    @php
        $d = $this->dashboard();
        $report = $d['report'];
        $price = $d['pricing'];
        $now = $d['bill_now'];
        $full = $d['bill_full'];
        $hasForecast = $d['forecast']['has_data'];
        $usedPercent = $now['free_storage_used_percent'];
        $barColor = $usedPercent >= 90 ? 'bg-rose-500' : ($usedPercent >= 60 ? 'bg-amber-500' : 'bg-emerald-500');
        $stateBadge = match ($d['state']) {
            'ready' => [__('Connected'), 'success'],
            'error' => [__('Problem'), 'danger'],
            'off' => [__('Switched off'), 'warning'],
            default => [__('Not set up'), 'gray'],
        };
        $healthColor = ['ok' => 'success', 'warning' => 'warning', 'danger' => 'danger', 'unknown' => 'gray'];
    @endphp

    <div class="space-y-6">
        {{-- Status --}}
        <x-filament::section>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3 text-sm">
                <div class="flex items-center gap-2">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('Status') }}</span>
                    <x-filament::badge :color="$stateBadge[1]">{{ $stateBadge[0] }}</x-filament::badge>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('Auto upload') }}</span>
                    <x-filament::badge :color="$d['auto_upload'] ? 'success' : 'gray'">{{ $d['auto_upload'] ? __('On') : __('Off') }}</x-filament::badge>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('Encryption') }}</span>
                    <x-filament::badge :color="$d['encrypted'] ? 'success' : ($d['encryption_required'] ? 'warning' : 'danger')">
                        {{ $d['encrypted'] ? __('On') : ($d['encryption_required'] ? __('Key missing: nothing is uploaded') : __('Off')) }}
                    </x-filament::badge>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('Last upload') }}</span>
                    <span class="font-medium">{{ $this->ago($d['last_upload']) }}</span>
                </div>
                @if ($d['bucket'])
                    <div class="flex items-center gap-2">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('Bucket') }}</span>
                        <span class="font-mono text-xs">{{ $d['bucket'] }}{{ $d['folder'] !== '' ? '/'.$d['folder'] : '' }}</span>
                    </div>
                @endif
                @if ($d['fetched_at'])
                    <div class="ms-auto text-xs text-gray-500 dark:text-gray-400">{{ __('Read from R2 :ago', ['ago' => $this->ago($d['fetched_at'])]) }}</div>
                @endif
            </div>

            @if ($d['state'] === 'error')
                <p class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ $d['error'] }}</p>
            @endif
        </x-filament::section>

        @if ($d['state'] === 'not_set_up')
            <x-filament::section :heading="__('Set up Cloudflare R2')" :description="__('A private bucket in your Cloudflare account keeps the encrypted copy of every backup, away from this server. It needs no Google Workspace.')">
                <ol class="list-decimal space-y-1.5 ps-5 text-sm">
                    <li>{{ __('Create a free Cloudflare account and open R2 (Cloudflare may ask for a payment card even to use the free allowance).') }}</li>
                    <li>{{ __('Create a bucket for backups only. Keep it private: do not turn on public access.') }}</li>
                    <li>{{ __('R2 → Manage R2 API Tokens → Create API token → "Object Read & Write", limited to that bucket. Copy the Access Key ID and Secret Access Key.') }}</li>
                    <li>{{ __('Press Configure above and fill in the Account ID, bucket name and the two keys. Press Test connection.') }}</li>
                    <li>{{ __('Make sure BACKUP_ENCRYPTION_KEY is set and a copy of it is kept outside the server: nothing is uploaded without it.') }}</li>
                </ol>
                @if ($d['missing'] !== [] && filled($d['bucket'] . $d['endpoint_host']))
                    <p class="mt-3 text-sm text-amber-600 dark:text-amber-400">{{ __('Still to be filled in: :items', ['items' => implode(', ', $d['missing'])]) }}</p>
                @endif
            </x-filament::section>
        @endif

        {{-- At a glance --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-filament::section>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Stored in R2') }}</p>
                <p class="mt-1 text-3xl font-semibold tracking-tight">{{ $this->bytes($report['bytes']) }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __(':count files', ['count' => number_format($report['count'])]) }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Free allowance used') }}</p>
                <p class="mt-1 text-3xl font-semibold tracking-tight">{{ number_format(min($usedPercent, 999), $usedPercent < 10 ? 1 : 0) }}%</p>
                <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div class="h-2 rounded-full {{ $barColor }}" style="width: {{ min(100, max(0, $usedPercent)) }}%"></div>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __(':used of :free GB free every month', ['used' => rtrim(rtrim(number_format($now['storage_gb'], 2), '0'), '.') ?: '0', 'free' => rtrim(rtrim(number_format($price['free_storage_gb'], 2), '0'), '.')]) }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Estimated bill this month') }}</p>
                <p class="mt-1 text-3xl font-semibold tracking-tight">{{ $this->usd($now['total_usd']) }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if ($now['total_bdt'] !== null) {{ $this->taka($now['total_bdt']) }} · @endif
                    {{ $now['within_free'] ? __('Inside the free allowance') : __('Over the free allowance') }}
                </p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Bill when the schedule has filled the bucket') }}</p>
                @if ($hasForecast)
                    <p class="mt-1 text-3xl font-semibold tracking-tight">{{ $this->usd($full['total_usd']) }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        @if ($full['total_bdt'] !== null) {{ $this->taka($full['total_bdt']) }} · @endif
                        {{ __('about :size stored', ['size' => $this->bytes($d['forecast']['steady_bytes'])]) }}
                    </p>
                @else
                    <p class="mt-1 text-3xl font-semibold tracking-tight">—</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Needs a few backups to measure; see the examples below.') }}</p>
                @endif
            </x-filament::section>
        </div>

        {{-- Health --}}
        @if ($d['health'] !== [])
            <x-filament::section :heading="__('Backup health')">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($d['health'] as $check)
                        <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-medium">{{ $check['label'] }}</span>
                                <x-filament::badge :color="$healthColor[$check['state']] ?? 'gray'">
                                    {{ match ($check['state']) { 'ok' => __('OK'), 'danger' => __('Overdue'), 'warning' => __('Attention'), default => $check['optional'] ? __('Not set up') : __('Waiting') } }}
                                </x-filament::badge>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</p>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- The bill --}}
        <x-filament::section :heading="__('What will it cost?')" :description="__('An estimate from Cloudflare\'s published prices. Cloudflare\'s own bill is the final word.')">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-1.5 pe-3 font-medium">{{ __('Item') }}</th>
                            <th class="py-1.5 pe-3 font-medium">{{ __('Price') }}</th>
                            <th class="py-1.5 pe-3 font-medium text-right">{{ __('Now') }}</th>
                            <th class="py-1.5 font-medium text-right">{{ __('When the bucket is full') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1.5 pe-3">{{ __('Storage') }}</td>
                            <td class="py-1.5 pe-3 text-gray-500 dark:text-gray-400">{{ __(':price per GB-month, first :free GB free', ['price' => $this->usd($price['storage_per_gb_month']), 'free' => rtrim(rtrim(number_format($price['free_storage_gb'], 2), '0'), '.')]) }}</td>
                            <td class="py-1.5 pe-3 text-right">{{ $this->bytes($report['bytes']) }} → {{ $this->usd($now['storage_usd']) }}</td>
                            <td class="py-1.5 text-right">{{ $hasForecast ? $this->bytes($d['forecast']['steady_bytes']).' → '.$this->usd($full['storage_usd']) : '—' }}</td>
                        </tr>
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1.5 pe-3">{{ __('Writes and lists (Class A)') }}</td>
                            <td class="py-1.5 pe-3 text-gray-500 dark:text-gray-400">{{ __(':price per million, first :free million free', ['price' => $this->usd($price['class_a_per_million']), 'free' => rtrim(rtrim(number_format($price['free_class_a'] / 1000000, 2), '0'), '.')]) }}</td>
                            <td class="py-1.5 pe-3 text-right">{{ number_format($now['class_a']) }} → {{ $this->usd($now['class_a_usd']) }}</td>
                            <td class="py-1.5 text-right">{{ $hasForecast ? number_format($full['class_a']).' → '.$this->usd($full['class_a_usd']) : '—' }}</td>
                        </tr>
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1.5 pe-3">{{ __('Reads and checks (Class B)') }}</td>
                            <td class="py-1.5 pe-3 text-gray-500 dark:text-gray-400">{{ __(':price per million, first :free million free', ['price' => $this->usd($price['class_b_per_million']), 'free' => rtrim(rtrim(number_format($price['free_class_b'] / 1000000, 2), '0'), '.')]) }}</td>
                            <td class="py-1.5 pe-3 text-right">{{ number_format($now['class_b']) }} → {{ $this->usd($now['class_b_usd']) }}</td>
                            <td class="py-1.5 text-right">{{ $hasForecast ? number_format($full['class_b']).' → '.$this->usd($full['class_b_usd']) : '—' }}</td>
                        </tr>
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1.5 pe-3">{{ __('Downloading a backup to restore') }}</td>
                            <td class="py-1.5 pe-3 text-gray-500 dark:text-gray-400" colspan="3">{{ __('Free: R2 does not charge for data going out.') }}</td>
                        </tr>
                        <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                            <td class="py-2 pe-3" colspan="2">{{ __('Total per month') }}</td>
                            <td class="py-2 pe-3 text-right">{{ $this->usd($now['total_usd']) }}@if ($now['total_bdt'] !== null) <span class="font-normal text-gray-500 dark:text-gray-400">({{ $this->taka($now['total_bdt']) }})</span>@endif</td>
                            <td class="py-2 text-right">{{ $hasForecast ? $this->usd($full['total_usd']) : '—' }}@if ($hasForecast && $full['total_bdt'] !== null) <span class="font-normal text-gray-500 dark:text-gray-400">({{ $this->taka($full['total_bdt']) }})</span>@endif</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if ($d['scenarios'] !== [])
                <h4 class="mt-5 text-sm font-semibold">{{ __('If your backups grow') }}</h4>
                <div class="mt-2 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-1.5 pe-3 font-medium">{{ __('Backups are') }}</th>
                                <th class="py-1.5 pe-3 font-medium text-right">{{ __('Stored when full') }}</th>
                                <th class="py-1.5 font-medium text-right">{{ __('Bill per month') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['scenarios'] as $scenario)
                                <tr class="border-t border-gray-200 dark:border-white/10">
                                    <td class="py-1.5 pe-3">{{ $scenario['factor'] == 1 ? __('As big as now') : __(':times times as big', ['times' => rtrim(rtrim(number_format($scenario['factor'], 1), '0'), '.')]) }}</td>
                                    <td class="py-1.5 pe-3 text-right">{{ $this->bytes($scenario['steady_bytes']) }}</td>
                                    <td class="py-1.5 text-right">{{ $this->usd($scenario['bill']['total_usd']) }}@if ($scenario['bill']['total_bdt'] !== null) <span class="text-gray-500 dark:text-gray-400">({{ $this->taka($scenario['bill']['total_bdt']) }})</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($d['examples'] !== [])
                <h4 class="mt-5 text-sm font-semibold">{{ __('Examples with this backup schedule') }}</h4>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Nothing to measure yet, so these use typical sizes. The real figures appear after the first backups reach R2.') }}</p>
                <div class="mt-2 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-1.5 pe-3 font-medium">{{ __('Database copy') }}</th>
                                <th class="py-1.5 pe-3 font-medium">{{ __('Full app archive') }}</th>
                                <th class="py-1.5 pe-3 font-medium text-right">{{ __('Stored when full') }}</th>
                                <th class="py-1.5 font-medium text-right">{{ __('Bill per month') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['examples'] as $example)
                                <tr class="border-t border-gray-200 dark:border-white/10">
                                    <td class="py-1.5 pe-3">{{ $this->bytes($example['database_bytes']) }}</td>
                                    <td class="py-1.5 pe-3">{{ $this->bytes($example['app_bytes']) }}</td>
                                    <td class="py-1.5 pe-3 text-right">{{ $this->bytes($example['steady_bytes']) }}</td>
                                    <td class="py-1.5 text-right">{{ $this->usd($example['bill']['total_usd']) }}@if ($example['bill']['total_bdt'] !== null) <span class="text-gray-500 dark:text-gray-400">({{ $this->taka($example['bill']['total_bdt']) }})</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <ul class="mt-5 list-disc space-y-1 ps-5 text-xs text-gray-500 dark:text-gray-400">
                <li>{{ __('Storage is billed on the highest size of each day, averaged over the month. 1 GB is counted as 1,000,000,000 bytes.') }}</li>
                <li>{{ __('The free allowance is for your whole Cloudflare account. If the Cloud Storage buckets (product images, customer files) are on the same account, they use it too, and this page cannot see them.') }}</li>
                <li>{{ __('Only the backup folder is counted. "When the bucket is full" means every kind has reached its keep limit (Backups page / backup.offsite.keep).') }}</li>
                <li>{{ __('Operations counted: what this app does when it uploads, trims and tests backups. Manual and before-restore copies are not planned for.') }}</li>
                <li>
                    {{ __('Prices as of :date.', ['date' => $price['as_of']]) }}
                    <a href="{{ $price['url'] }}" target="_blank" rel="noopener" class="underline">{{ __('Check Cloudflare\'s price list') }}</a>
                    {{ __('If it changed, update config/backup.php (r2.pricing) or the BACKUP_R2_PRICE_* settings.') }}
                </li>
                @if ($price['usd_bdt_rate'] === null)
                    <li>{{ __('Add a dollar-to-taka rate under Configure to see the estimate in taka as well.') }}</li>
                @endif
            </ul>
        </x-filament::section>

        {{-- What is stored --}}
        <x-filament::section :heading="__('Copies by type')" :description="__('How many copies of each type R2 holds, against how many are kept.')">
            @if ($report['groups'] === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $d['state'] === 'not_set_up' ? __('Nothing to show until R2 is set up.') : __('No backup copies are stored in R2 yet.') }}</p>
            @else
                @php($biggest = max(1, collect($report['groups'])->max('bytes')))
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-1.5 pe-3 font-medium">{{ __('Type') }}</th>
                                <th class="py-1.5 pe-3 font-medium text-right">{{ __('Copies') }}</th>
                                <th class="py-1.5 pe-3 font-medium">{{ __('Size') }}</th>
                                <th class="py-1.5 pe-3 font-medium">{{ __('Newest') }}</th>
                                <th class="py-1.5 font-medium">{{ __('Oldest') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['groups'] as $group)
                                <tr class="border-t border-gray-200 dark:border-white/10">
                                    <td class="py-1.5 pe-3">{{ $this->kindLabel($group['kind']) }} · {{ $this->scopeLabel($group['scope']) }}</td>
                                    <td class="py-1.5 pe-3 text-right">{{ $group['count'] }} / {{ $group['keep'] }}</td>
                                    <td class="py-1.5 pe-3">
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 w-24 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                                <div class="h-2 rounded-full bg-primary-500" style="width: {{ max(2, round($group['bytes'] / $biggest * 100)) }}%"></div>
                                            </div>
                                            <span>{{ $this->bytes($group['bytes']) }}</span>
                                        </div>
                                    </td>
                                    <td class="py-1.5 pe-3">{{ $this->ago($group['newest_at']) }}</td>
                                    <td class="py-1.5">{{ $this->ago($group['oldest_at']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($report['other']['count'] > 0)
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __(':count other files (:size) are in the folder. They are counted in the bill and never deleted by this app.', ['count' => $report['other']['count'], 'size' => $this->bytes($report['other']['bytes'])]) }}</p>
                @endif
            @endif
        </x-filament::section>

        {{-- Latest files --}}
        <x-filament::section :heading="__('Latest files')">
            @if ($report['files'] === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No backup copies are stored in R2 yet.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-1.5 pe-3 font-medium">{{ __('File') }}</th>
                                <th class="py-1.5 pe-3 font-medium">{{ __('Type') }}</th>
                                <th class="py-1.5 pe-3 font-medium text-right">{{ __('Size') }}</th>
                                <th class="py-1.5 font-medium">{{ __('Uploaded') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['files'] as $file)
                                <tr class="border-t border-gray-200 dark:border-white/10">
                                    <td class="py-1.5 pe-3 font-mono text-xs">{{ $file['name'] }}@if ($file['encrypted']) <x-filament::badge size="sm" color="success" class="ms-1">{{ __('Encrypted') }}</x-filament::badge>@endif</td>
                                    <td class="py-1.5 pe-3">{{ $this->kindLabel($file['kind']) }} · {{ $this->scopeLabel($file['scope']) }}</td>
                                    <td class="py-1.5 pe-3 text-right">{{ $this->bytes($file['size']) }}</td>
                                    <td class="py-1.5">{{ $this->ago($file['at']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- Recent uploads --}}
        <x-filament::section :heading="__('Recent uploads and tests')" :description="__('The latest backups that were sent to R2, restore tests read from it, and attempts that failed.')">
            @if ($d['recent_runs'] === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing has been sent to R2 yet.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-1.5 pe-3 font-medium">{{ __('Status') }}</th>
                                <th class="py-1.5 pe-3 font-medium">{{ __('What') }}</th>
                                <th class="py-1.5 pe-3 font-medium">{{ __('File') }}</th>
                                <th class="py-1.5 pe-3 font-medium text-right">{{ __('Size') }}</th>
                                <th class="py-1.5 pe-3 font-medium">{{ __('Started') }}</th>
                                <th class="py-1.5 font-medium">{{ __('Note') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['recent_runs'] as $run)
                                <tr class="border-t border-gray-200 align-top dark:border-white/10">
                                    <td class="py-1.5 pe-3">
                                        <x-filament::badge :color="$run['status'] === 'success' ? 'success' : ($run['status'] === 'failed' ? 'danger' : 'warning')">
                                            {{ $run['status'] === 'success' ? __('Success') : ($run['status'] === 'failed' ? __('Failed') : __('Running')) }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="py-1.5 pe-3">{{ $run['what'] }}</td>
                                    <td class="py-1.5 pe-3 font-mono text-xs">{{ $run['filename'] ?: '—' }}</td>
                                    <td class="py-1.5 pe-3 text-right">{{ $run['size'] ? $this->bytes($run['size']) : '—' }}</td>
                                    <td class="py-1.5 pe-3">{{ $this->ago($run['at']) }}</td>
                                    <td class="py-1.5 text-xs text-gray-500 dark:text-gray-400">{{ $run['note'] ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
