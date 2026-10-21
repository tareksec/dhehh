@php
    use App\Filament\Reseller\Pages\CreateOrder;
    use App\Filament\Reseller\Pages\MyStore;
    use App\Filament\Reseller\Pages\Orders;
    use App\Filament\Reseller\Pages\Products;
    use App\Filament\Reseller\Pages\Withdrawals;
    use App\Models\Order;
    use App\Support\ClipboardCopy;
    use App\Support\MoneyFormatter;
    use Illuminate\Support\Js;
@endphp

<x-filament-panels::page>
    @php
        $reseller = $this->reseller();
    @endphp

    @if ($reseller->reseller_status === 'pending')
        <x-filament::section>
            <div class="flex flex-col items-center gap-3 py-8 text-center">
                <x-filament::icon icon="heroicon-o-clock" class="h-10 w-10 text-warning-500" />
                <h2 class="text-lg font-semibold">{{ __('Your application is being reviewed') }}</h2>
                <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">
                    {{ __('We will let you know once it is approved. This usually does not take long.') }}
                </p>
            </div>
        </x-filament::section>
    @elseif ($reseller->reseller_status === 'rejected')
        <x-filament::section>
            <div class="flex flex-col items-center gap-3 py-8 text-center">
                <x-filament::icon icon="heroicon-o-x-circle" class="h-10 w-10 text-danger-500" />
                <h2 class="text-lg font-semibold">{{ __('Your application was not approved') }}</h2>
                @if ($reseller->reseller_note)
                    <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">{{ $reseller->reseller_note }}</p>
                @endif
            </div>
        </x-filament::section>
    @else
        @php
            $currency = $this->currency();
            $balances = $this->balances();
            $kpis = $this->kpis();
            $pipeline = $this->pipeline();
            $recentOrders = $this->recentOrders();
            $announcement = $this->announcement();
            $firstSteps = $this->firstSteps();
            $stepsDone = collect($firstSteps)->where('done', true)->count();
            $bestSellers = $this->bestSellers();
            $storeUrl = $this->storeUrl();
            $storeQr = $this->storeQrCode();
            $card = 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
            // Whole class names, so Tailwind's scanner finds them.
            $tones = [
                'primary' => 'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400',
                'success' => 'bg-success-50 text-success-600 dark:bg-success-500/10 dark:text-success-400',
                'info' => 'bg-info-50 text-info-600 dark:bg-info-500/10 dark:text-info-400',
                'warning' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/10 dark:text-warning-400',
                'gray' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300',
            ];
            $sparkTones = [
                'primary' => 'text-primary-500',
                'info' => 'text-info-500',
                'success' => 'text-success-500',
            ];
            $overview = [
                [
                    'label' => __('Waiting for delivery'),
                    'value' => $this->waitingForDeliveryCount(),
                    'icon' => 'heroicon-o-truck',
                    'tone' => 'warning',
                    'metric' => null,
                ],
                [
                    'label' => __('This month\'s orders'),
                    'value' => $kpis['orders']['value'],
                    'icon' => 'heroicon-o-shopping-bag',
                    'tone' => 'primary',
                    'metric' => 'orders',
                ],
                [
                    'label' => __('This month\'s sales'),
                    'value' => MoneyFormatter::currency($kpis['sales']['value'], $currency),
                    'icon' => 'heroicon-o-banknotes',
                    'tone' => 'info',
                    'metric' => 'sales',
                ],
                [
                    'label' => __('This month\'s profit'),
                    'value' => MoneyFormatter::currency($kpis['commission']['value'], $currency),
                    'icon' => 'heroicon-o-receipt-percent',
                    'tone' => 'success',
                    'metric' => 'commission',
                ],
            ];
            $stageIcons = [
                Order::STAGE_CONFIRMED => ['heroicon-o-check-circle', 'gray'],
                Order::STAGE_PROCESSING => ['heroicon-o-cog-6-tooth', 'warning'],
                Order::STAGE_SHIPPING => ['heroicon-o-truck', 'info'],
                Order::STAGE_COMPLETED => ['heroicon-o-check-badge', 'success'],
            ];
        @endphp

        <div class="space-y-6">
            {{-- The company's notice for resellers (Reseller Settings → Portal announcement) --}}
            @if ($announcement)
                <aside class="flex items-start gap-3 rounded-2xl bg-warning-50 p-4 text-warning-900 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-100 dark:ring-warning-400/20" role="status" data-reseller-announcement>
                    <x-filament::icon icon="heroicon-o-megaphone" class="mt-0.5 h-6 w-6 shrink-0 text-warning-600 dark:text-warning-400" />
                    <p class="min-w-0 flex-1 whitespace-pre-line break-words text-sm font-medium">{{ $announcement }}</p>
                </aside>
            @endif

            {{-- Welcome and the money that can be withdrawn now --}}
            <section class="relative overflow-hidden rounded-3xl bg-linear-to-br from-primary-700 via-primary-800 to-primary-950 p-6 text-white shadow-lg sm:p-8">
                <div class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-white/10"></div>
                <div class="pointer-events-none absolute -bottom-24 right-32 h-52 w-52 rounded-full bg-white/5"></div>

                <div class="relative grid gap-6 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div>
                        <p class="text-sm text-white/80">{{ $this->greeting() }},</p>
                        <h2 class="mt-0.5 text-2xl font-bold sm:text-3xl">{{ $reseller->name }}</h2>
                        @if ($reseller->resellerLevel)
                            <span data-reseller-level class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-white ring-1 ring-white/30">
                                <x-filament::icon icon="heroicon-m-trophy" class="h-4 w-4" />
                                {{ $reseller->resellerLevel->name }}
                            </span>
                        @endif

                        <p class="mt-6 text-xs font-medium uppercase tracking-wider text-white/70">{{ __('Available to withdraw') }}</p>
                        <p class="mt-1 text-4xl font-extrabold tracking-tight sm:text-5xl">{{ MoneyFormatter::currency($balances['available'], $currency) }}</p>

                        <div class="mt-6 flex flex-wrap gap-3">
                            <a href="{{ Withdrawals::getUrl() }}" class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-primary-800 shadow-sm transition hover:bg-primary-50">
                                <x-filament::icon icon="heroicon-m-banknotes" class="h-5 w-5" />
                                {{ __('Withdraw') }}
                            </a>
                            <a href="{{ CreateOrder::getUrl() }}" class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/30 transition hover:bg-white/25">
                                <x-filament::icon icon="heroicon-m-plus-circle" class="h-5 w-5" />
                                {{ __('Create order') }}
                            </a>
                        </div>
                    </div>

                    <dl class="grid grid-cols-3 gap-3 text-sm lg:w-96">
                        <div class="rounded-2xl bg-white/10 p-3 ring-1 ring-white/15 backdrop-blur-sm">
                            <dt class="text-xs text-white/70">{{ __('Pending profit') }}</dt>
                            <dd class="mt-1 font-semibold">{{ MoneyFormatter::currency($balances['pending'], $currency) }}</dd>
                        </div>
                        <div class="rounded-2xl bg-white/10 p-3 ring-1 ring-white/15 backdrop-blur-sm">
                            <dt class="text-xs text-white/70">{{ __('Withdrawal in process') }}</dt>
                            <dd class="mt-1 font-semibold">{{ MoneyFormatter::currency($balances['in_process'], $currency) }}</dd>
                        </div>
                        <div class="rounded-2xl bg-white/10 p-3 ring-1 ring-white/15 backdrop-blur-sm">
                            <dt class="text-xs text-white/70">{{ __('Total withdrawn') }}</dt>
                            <dd class="mt-1 font-semibold">{{ MoneyFormatter::currency($balances['withdrawn'], $currency) }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            {{-- The first things to set up, until all are done --}}
            @if ($stepsDone < count($firstSteps))
                <section class="{{ $card }} p-5 sm:p-6" data-reseller-first-steps>
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-base font-semibold">{{ __('Get started') }}</h3>
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __(':done of :total steps done', ['done' => $stepsDone, 'total' => count($firstSteps)]) }}</span>
                    </div>
                    <div class="mb-4 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="{{ count($firstSteps) }}" aria-valuenow="{{ $stepsDone }}">
                        <div class="h-full rounded-full bg-primary-500" style="width: {{ round($stepsDone / count($firstSteps) * 100) }}%"></div>
                    </div>
                    <ol class="grid gap-3 sm:grid-cols-2">
                        @foreach ($firstSteps as $step)
                            <li>
                                <a href="{{ $step['url'] }}" data-first-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? '1' : '0' }}" class="flex items-start gap-3 rounded-xl p-3 ring-1 ring-gray-950/5 transition hover:ring-primary-500/50 dark:ring-white/10">
                                    @if ($step['done'])
                                        <x-filament::icon icon="heroicon-m-check-circle" class="mt-0.5 h-6 w-6 shrink-0 text-success-500" />
                                    @else
                                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 border-gray-300 dark:border-white/20"></span>
                                    @endif
                                    <span class="min-w-0">
                                        <span @class(['block text-sm font-semibold', 'text-gray-400 line-through dark:text-gray-500' => $step['done']])>{{ $step['label'] }}</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $step['hint'] }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif

            {{-- One-tap shortcuts --}}
            <nav class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="{{ __('Quick actions') }}">
                @php
                    $tile = 'group flex items-center gap-3 rounded-2xl bg-white p-4 text-left shadow-sm ring-1 ring-gray-950/5 transition hover:-translate-y-0.5 hover:shadow-md hover:ring-primary-500/40 dark:bg-gray-900 dark:ring-white/10';
                    $tileIcon = 'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 transition group-hover:bg-primary-600 group-hover:text-white dark:bg-primary-500/10 dark:text-primary-400';
                @endphp

                <a href="{{ CreateOrder::getUrl() }}" class="{{ $tile }}">
                    <span class="{{ $tileIcon }}"><x-filament::icon icon="heroicon-o-plus-circle" class="h-6 w-6" /></span>
                    <span class="text-sm font-semibold">{{ __('Create order') }}</span>
                </a>
                <a href="{{ Products::getUrl() }}" class="{{ $tile }}">
                    <span class="{{ $tileIcon }}"><x-filament::icon icon="heroicon-o-squares-2x2" class="h-6 w-6" /></span>
                    <span class="text-sm font-semibold">{{ __('Add products') }}</span>
                </a>
                <a href="{{ Withdrawals::getUrl() }}" class="{{ $tile }}">
                    <span class="{{ $tileIcon }}"><x-filament::icon icon="heroicon-o-banknotes" class="h-6 w-6" /></span>
                    <span class="text-sm font-semibold">{{ __('Withdraw') }}</span>
                </a>
                @if ($storeUrl)
                    <button
                        type="button"
                        class="{{ $tile }}"
                        x-on:click="
                            if (navigator.share) {
                                navigator.share({ url: {{ Js::from($storeUrl) }} }).catch(() => {})
                            } else {
                                {{ ClipboardCopy::alpineHandler($storeUrl, __('Copied to clipboard')) }}
                            }
                        "
                    >
                        <span class="{{ $tileIcon }}"><x-filament::icon icon="heroicon-o-share" class="h-6 w-6" /></span>
                        <span class="text-sm font-semibold">{{ __('Share store') }}</span>
                    </button>
                @else
                    <a href="{{ MyStore::getUrl() }}" class="{{ $tile }}">
                        <span class="{{ $tileIcon }}"><x-filament::icon icon="heroicon-o-building-storefront" class="h-6 w-6" /></span>
                        <span class="text-sm font-semibold">{{ MyStore::getNavigationLabel() }}</span>
                    </a>
                @endif
            </nav>

            {{-- This month at a glance --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
                @foreach ($overview as $item)
                    @php
                        $stats = $item['metric'] ? $kpis[$item['metric']] : null;
                    @endphp
                    <div class="{{ $card }} flex flex-col p-4">
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $item['label'] }}</span>
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $tones[$item['tone']] }}">
                                <x-filament::icon :icon="$item['icon']" class="h-4 w-4" />
                            </span>
                        </div>

                        <div class="mt-2 break-words text-xl font-bold sm:text-2xl">{{ $item['value'] }}</div>

                        @if ($stats)
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                @if ($stats['delta'] !== null)
                                    <span
                                        title="{{ __('Compared with the same days of last month') }}"
                                        @class([
                                            'inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 font-semibold',
                                            'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $stats['delta'] > 0,
                                            'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' => $stats['delta'] < 0,
                                            'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => $stats['delta'] === 0,
                                        ])
                                    >
                                        @if ($stats['delta'] > 0)
                                            <x-filament::icon icon="heroicon-m-arrow-up-right" class="h-3 w-3" />
                                        @elseif ($stats['delta'] < 0)
                                            <x-filament::icon icon="heroicon-m-arrow-down-right" class="h-3 w-3" />
                                        @endif
                                        {{ abs($stats['delta']) }}%
                                    </span>
                                    <span class="text-gray-500 dark:text-gray-400">{{ __('vs last month') }}</span>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">{{ __('This month so far') }}</span>
                                @endif
                            </div>

                            <div class="mt-auto pt-3">
                                @include('filament.reseller.pages.partials.sparkline', ['values' => $stats['spark'], 'class' => $sparkTones[$item['tone']]])
                            </div>
                        @else
                            <div class="mt-auto pt-3 text-xs">
                                <a href="{{ Orders::getUrl(['stage' => Order::STAGE_SHIPPING]) }}" class="inline-flex items-center gap-1 font-semibold text-primary-600 hover:underline dark:text-primary-400">
                                    {{ __('See parcels on the way') }}
                                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                                </a>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- The reseller's own store --}}
            @if ($this->hasStoreSlug() && $storeUrl)
                <section class="{{ $card }} p-5 sm:p-6">
                    <div class="flex flex-col gap-5 md:flex-row md:items-center">
                        <div class="min-w-0 flex-1 space-y-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-semibold">{{ __('Your store') }}</h3>
                                @if ($this->subdomainIsSettingUp())
                                    <a href="{{ MyStore::getUrl() }}" class="inline-flex items-center gap-1.5 rounded-full bg-warning-50 px-2.5 py-0.5 text-xs font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                                        <x-filament::loading-indicator class="h-3 w-3" />
                                        {{ __('Setting up your store address') }}
                                    </a>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-success-50 px-2.5 py-0.5 text-xs font-semibold text-success-700 dark:bg-success-500/10 dark:text-success-400">
                                        <span class="h-1.5 w-1.5 rounded-full bg-success-500"></span>
                                        {{ __('Live') }}
                                    </span>
                                @endif
                            </div>

                            {{-- Reads like a browser address bar --}}
                            <div class="flex items-center gap-2 rounded-xl bg-gray-50 px-3 py-2.5 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                                <x-filament::icon icon="heroicon-m-lock-closed" class="h-4 w-4 shrink-0 text-success-600 dark:text-success-400" />
                                <span class="min-w-0 flex-1 truncate text-sm font-medium" dir="ltr">{{ preg_replace('#^https?://#', '', $storeUrl) }}</span>
                                <button
                                    type="button"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 shadow-sm ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:bg-white/10 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/20"
                                    x-on:click="{{ ClipboardCopy::alpineHandler($storeUrl, __('Copied to clipboard')) }}"
                                >
                                    <x-filament::icon icon="heroicon-m-clipboard-document" class="h-4 w-4" />
                                    {{ __('Copy link') }}
                                </button>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <x-filament::button tag="a" size="sm" icon="heroicon-m-arrow-top-right-on-square" :href="$storeUrl" target="_blank" rel="noopener">
                                    {{ __('Visit store') }}
                                </x-filament::button>
                                <x-filament::button tag="a" size="sm" color="success" icon="heroicon-m-chat-bubble-left-ellipsis" href="https://wa.me/?text={{ urlencode($storeUrl) }}" target="_blank" rel="noopener">
                                    {{ __('Share on WhatsApp') }}
                                </x-filament::button>
                                <x-filament::button tag="a" size="sm" color="info" icon="heroicon-m-user-group" href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($storeUrl) }}" target="_blank" rel="noopener">
                                    {{ __('Share on Facebook') }}
                                </x-filament::button>
                            </div>
                        </div>

                        @if ($storeQr)
                            <div class="flex shrink-0 items-center gap-3 md:flex-col md:gap-2">
                                <img src="{{ $storeQr }}" alt="{{ __('QR code of your store link') }}" class="h-28 w-28 rounded-2xl bg-white p-2 ring-1 ring-gray-950/10" width="112" height="112">
                                <p class="max-w-28 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('Scan to open your store') }}</p>
                            </div>
                        @endif
                    </div>
                </section>
            @else
                <section class="{{ $card }} flex flex-col items-start gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                    <div class="flex items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $tones['warning'] }}">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-6 w-6" />
                        </span>
                        <p class="text-sm">{{ __('Your store link is not ready yet. Add products to your store to get one.') }}</p>
                    </div>
                    <x-filament::button tag="a" size="sm" :href="Products::getUrl()">{{ __('Add products') }}</x-filament::button>
                </section>
            @endif

            {{-- Where the orders are --}}
            <section class="{{ $card }} p-5 sm:p-6">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h3 class="text-base font-semibold">{{ __('Order pipeline') }}</h3>
                    <a href="{{ Orders::getUrl() }}" class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ __('View all orders') }}</a>
                </div>

                <ol class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ($pipeline['steps'] as $stage => $count)
                        <li>
                            <a href="{{ Orders::getUrl(['stage' => $stage]) }}" class="group block rounded-xl p-4 ring-1 ring-gray-950/5 transition hover:ring-primary-500/50 dark:ring-white/10">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-lg {{ $tones[$stageIcons[$stage][1]] }}">
                                        <x-filament::icon :icon="$stageIcons[$stage][0]" class="h-5 w-5" />
                                    </span>
                                    <span class="text-2xl font-bold">{{ $count }}</span>
                                </div>
                                <div class="mt-2 text-xs font-medium text-gray-500 group-hover:text-primary-600 dark:text-gray-400 dark:group-hover:text-primary-400">{{ __(Order::WORKFLOW_STAGES[$stage]) }}</div>
                            </a>
                        </li>
                    @endforeach
                </ol>

                @if ($pipeline['closed'])
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        @foreach ($pipeline['closed'] as $stage => $count)
                            <a href="{{ Orders::getUrl(['stage' => $stage]) }}" class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 font-medium text-gray-600 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20">
                                {{ __(Order::WORKFLOW_STAGES[$stage]) }}
                                <span class="font-bold">{{ $count }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <div class="grid gap-6 lg:grid-cols-5">
                {{-- The latest five orders --}}
                <section class="{{ $card }} p-5 sm:p-6 lg:col-span-3">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h3 class="text-base font-semibold">{{ __('Recent orders') }}</h3>
                        @if ($recentOrders->isNotEmpty())
                            <a href="{{ Orders::getUrl() }}" class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ __('View all') }}</a>
                        @endif
                    </div>

                    @if ($recentOrders->isEmpty())
                        <div class="flex flex-col items-center gap-3 py-8 text-center">
                            <span class="flex h-12 w-12 items-center justify-center rounded-2xl {{ $tones['primary'] }}">
                                <x-filament::icon icon="heroicon-o-shopping-bag" class="h-6 w-6" />
                            </span>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No orders yet.') }}</p>
                            <x-filament::button tag="a" size="sm" :href="CreateOrder::getUrl()">{{ __('Create order') }}</x-filament::button>
                        </div>
                    @else
                        <ul class="divide-y divide-gray-100 dark:divide-white/10">
                            @foreach ($recentOrders as $order)
                                @php
                                    $summary = $this->orderSummary($order);
                                    $stage = $order->workflowStage();
                                    $stageColor = match ($stage) {
                                        Order::STAGE_DELIVERED, Order::STAGE_COMPLETED => 'success',
                                        Order::STAGE_SHIPPING => 'info',
                                        Order::STAGE_PROCESSING => 'warning',
                                        Order::STAGE_CANCELLED, Order::STAGE_RETURNED, Order::STAGE_REFUNDED => 'danger',
                                        default => 'gray',
                                    };
                                @endphp
                                <li class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                    @if ($summary['image'])
                                        <img src="{{ $summary['image'] }}" alt="" class="h-12 w-12 shrink-0 rounded-xl object-cover ring-1 ring-gray-950/10 dark:ring-white/10" loading="lazy">
                                    @else
                                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl {{ $tones['gray'] }}">
                                            <x-filament::icon icon="heroicon-o-photo" class="h-5 w-5" />
                                        </span>
                                    @endif

                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-semibold">
                                            {{ $summary['name'] }}
                                            @if ($summary['more'] > 0)
                                                <span class="font-normal text-gray-500 dark:text-gray-400">+{{ $summary['more'] }}</span>
                                            @endif
                                        </div>
                                        <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                                            {{ $order->order_number }} · {{ $order->order_date?->format('d M Y') }}
                                        </div>
                                    </div>

                                    <div class="flex shrink-0 flex-col items-end gap-1">
                                        <span class="text-sm font-semibold">{{ MoneyFormatter::currency($order->buyerTotal(), $currency) }}</span>
                                        <x-filament::badge :color="$stageColor" size="sm">
                                            {{ __(Order::WORKFLOW_STAGES[$stage] ?? Order::STATUSES[$order->status] ?? $order->status) }}
                                        </x-filament::badge>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- Commission over the last six months --}}
                <div class="lg:col-span-2 [&>div]:h-full [&_.fi-section]:h-full">
                    @livewire(\App\Filament\Reseller\Widgets\CommissionTrend::class)
                </div>
            </div>
            {{-- What sold the most in the last 30 days --}}
            @if ($bestSellers !== [])
                <section class="{{ $card }} p-5 sm:p-6" data-reseller-best-sellers>
                    <div class="mb-4">
                        <h3 class="text-base font-semibold">{{ __('Best-selling products') }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Pieces sold in the last 30 days.') }}</p>
                    </div>
                    <ol class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($bestSellers as $index => $seller)
                            <li class="flex items-center gap-3 rounded-xl p-3 ring-1 ring-gray-950/5 dark:ring-white/10">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-50 text-xs font-bold text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">{{ $index + 1 }}</span>
                                @if ($seller['image'])
                                    <img src="{{ $seller['image'] }}" alt="" class="h-12 w-12 shrink-0 rounded-xl object-cover ring-1 ring-gray-950/10 dark:ring-white/10" loading="lazy">
                                @else
                                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl {{ $tones['gray'] }}">
                                        <x-filament::icon icon="heroicon-o-photo" class="h-5 w-5" />
                                    </span>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-semibold">{{ $seller['name'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $seller['pieces'] === 1 ? __(':count piece', ['count' => 1]) : __(':count pieces', ['count' => $seller['pieces']]) }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
