@php
    use App\Support\MoneyFormatter;

    $money = fn (float $amount): string => MoneyFormatter::currency($amount, $currency);
    $steps = [
        ['title' => __('Apply'), 'body' => __('Fill in your name, phone, business name and a password. It takes about a minute.')],
        ['title' => __('Get approved'), 'body' => __('The store reviews your application. Sign in with the same phone and password to see its status.')],
        ['title' => __('Set up your store'), 'body' => __('Pick the products for your store, set your own prices and share your store link.')],
        ['title' => __('Earn on every sale'), 'body' => __('You earn the difference between your price and your cost on every delivered order.')],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Reseller guide') }} · {{ $companyName }}</title>
    <meta name="description" content="{{ __('How the :company reseller program works and the rules every reseller follows.', ['company' => $companyName]) }}">
    <script>
        (function () {
            var dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
            document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
        })();
    </script>
    @vite(['resources/css/app.css'])
    <style>
        :root { --brand: {{ $brandColor }}; }
        .guide-brand-soft { background: color-mix(in srgb, var(--brand) 10%, transparent); }
        .guide-hero { background:
            radial-gradient(60rem 30rem at 85% -10%, color-mix(in srgb, var(--brand) 28%, transparent), transparent 70%),
            radial-gradient(40rem 24rem at -10% 110%, color-mix(in srgb, var(--brand) 16%, transparent), transparent 70%); }
    </style>
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100" data-reseller-guide>
    <header class="sticky top-0 z-30 border-b border-gray-200/70 bg-white/80 backdrop-blur dark:border-white/10 dark:bg-gray-950/80">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-3 px-4 sm:px-6">
            <a class="flex min-w-0 items-center gap-3" href="#top">
                @if ($logoUrl)
                    <img class="h-8 w-auto max-w-32 object-contain" src="{{ $logoUrl }}" alt="{{ $companyName }}">
                @else
                    <span class="truncate text-base font-semibold">{{ $companyName }}</span>
                @endif
                <span class="hidden rounded-full px-2.5 py-0.5 text-xs font-semibold text-[var(--brand)] guide-brand-soft sm:inline">{{ __('Reseller guide') }}</span>
            </a>
            <nav class="flex shrink-0 items-center gap-2">
                <a class="inline-flex min-h-10 items-center rounded-xl px-3 text-sm font-medium text-gray-700 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10" href="{{ $loginUrl }}">{{ __('Sign in') }}</a>
                <a class="inline-flex min-h-10 items-center rounded-xl bg-[var(--brand)] px-4 text-sm font-semibold text-white shadow-sm transition hover:opacity-90" href="{{ $applyUrl }}" data-guide-cta>{{ __('Apply now') }}</a>
            </nav>
        </div>
    </header>

    <main id="top">
        {{-- Hero --}}
        <section class="guide-hero relative overflow-hidden border-b border-gray-200/70 dark:border-white/10">
            <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
                <p class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white/70 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-[var(--brand)] dark:border-white/10 dark:bg-white/5">
                    <span class="h-1.5 w-1.5 rounded-full bg-[var(--brand)]"></span>
                    {{ __('Reseller program') }}
                </p>
                <h1 class="mt-5 max-w-3xl text-4xl font-bold tracking-tight sm:text-5xl lg:text-6xl">
                    {{ __('Sell :company products from your own store.', ['company' => $companyName]) }}
                </h1>
                <p class="mt-5 max-w-2xl text-lg leading-8 text-gray-600 dark:text-gray-300">
                    {{ filled($intro) ? $intro : __('No stock to buy and no warehouse to run. You choose the products, set your own prices and share your store; the store keeps the stock. Read how it works and the rules below before you apply.') }}
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a class="inline-flex min-h-12 items-center gap-2 rounded-xl bg-[var(--brand)] px-6 text-base font-semibold text-white shadow-lg shadow-black/10 transition hover:opacity-90" href="{{ $applyUrl }}" data-guide-cta>
                        {{ __('Apply now') }}
                        <svg class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                    </a>
                    <a class="inline-flex min-h-12 items-center rounded-xl border border-gray-300 bg-white/70 px-6 text-base font-semibold transition hover:border-gray-900 dark:border-white/15 dark:bg-white/5 dark:hover:border-white/40" href="#rules">
                        {{ __('Read the rules') }}
                    </a>
                </div>
                <nav class="mt-10 flex flex-wrap gap-2 text-sm" aria-label="{{ __('On this page') }}">
                    @foreach (array_filter([
                        'how' => __('How it works'),
                        'money' => __('Pricing and commission'),
                        'store' => __('Your store'),
                        'orders' => __('Orders and delivery'),
                        'rules' => __('Rules'),
                    ]) as $anchor => $label)
                        <a class="rounded-full border border-gray-200 bg-white/70 px-3 py-1.5 text-gray-700 transition hover:border-[var(--brand)] hover:text-[var(--brand)] dark:border-white/10 dark:bg-white/5 dark:text-gray-300" href="#{{ $anchor }}">{{ $label }}</a>
                    @endforeach
                </nav>
            </div>
        </section>

        {{-- How it works --}}
        <section id="how" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-16 sm:px-6">
            <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('How it works') }}</h2>
            <p class="mt-2 text-gray-600 dark:text-gray-400">{{ __('Four steps from applying to your first commission.') }}</p>
            <ol class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($steps as $i => $step)
                    <li class="relative rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl text-base font-bold text-[var(--brand)] guide-brand-soft">{{ $i + 1 }}</span>
                        <h3 class="mt-4 font-semibold">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ $step['body'] }}</p>
                    </li>
                @endforeach
            </ol>
        </section>

        {{-- Money --}}
        <section id="money" class="scroll-mt-20 border-y border-gray-200/70 bg-white dark:border-white/10 dark:bg-gray-900/40">
            <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
                <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('Pricing and commission') }}</h2>
                <div class="mt-8 grid gap-4 lg:grid-cols-3">
                    <article class="rounded-2xl border border-gray-200 p-6 dark:border-white/10">
                        <h3 class="font-semibold">{{ __('Your price, your profit') }}</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ __('Every product shows your cost in the portal. You can sell at your cost or any price above it; the difference is your commission.') }}</p>
                        <div class="mt-5 rounded-xl bg-gray-50 p-4 text-sm dark:bg-white/5">
                            <div class="flex justify-between"><span class="text-gray-500 dark:text-gray-400">{{ __('Your price') }}</span><span class="font-medium">A</span></div>
                            <div class="mt-1 flex justify-between"><span class="text-gray-500 dark:text-gray-400">{{ __('Your cost') }}</span><span class="font-medium">− B</span></div>
                            <div class="mt-2 flex justify-between border-t border-gray-200 pt-2 font-semibold text-[var(--brand)] dark:border-white/10"><span>{{ __('Your commission') }}</span><span>A − B</span></div>
                        </div>
                    </article>
                    <article class="rounded-2xl border border-gray-200 p-6 dark:border-white/10">
                        <h3 class="font-semibold">{{ __('When you earn it') }}</h3>
                        <ul class="mt-3 space-y-3 text-sm leading-6 text-gray-600 dark:text-gray-400">
                            <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]"></span>{{ __('A commission waits while the order is on its way and becomes yours to withdraw once the order is delivered.') }}</li>
                            <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]"></span>{{ __('Cancelled, returned or refunded orders earn no commission. If a delivered order is returned later, its commission is taken back.') }}</li>
                        </ul>
                    </article>
                    <article class="rounded-2xl border border-gray-200 p-6 dark:border-white/10">
                        <h3 class="font-semibold">{{ __('Withdrawing your money') }}</h3>
                        <ul class="mt-3 space-y-3 text-sm leading-6 text-gray-600 dark:text-gray-400">
                            <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]"></span>{{ __('Request a withdrawal from your available balance to bKash, Nagad, Rocket or a bank account.') }}</li>
                            <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]"></span>{{ __('The store reviews the request and pays it; you see its status in the portal.') }}</li>
                            @if ($showFigures && $minWithdrawal > 0)
                                <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]"></span>{{ __('The smallest withdrawal is :amount.', ['amount' => $money($minWithdrawal)]) }}</li>
                            @endif
                        </ul>
                    </article>
                </div>
            </div>
        </section>

        {{-- Store --}}
        <section id="store" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-16 sm:px-6">
            <div class="grid items-center gap-10 lg:grid-cols-2">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('Your own store') }}</h2>
                    <p class="mt-3 leading-7 text-gray-600 dark:text-gray-400">{{ __('Every approved reseller gets an online store with only the products they picked, at their prices. Your customers see your shop name on it, not the store\'s.') }}</p>
                    <ul class="mt-6 space-y-3 text-sm">
                        <li class="flex gap-3"><svg class="h-5 w-5 shrink-0 text-[var(--brand)]" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>{{ __('Share your store or any product on WhatsApp and Facebook from the portal.') }}</li>
                        <li class="flex gap-3"><svg class="h-5 w-5 shrink-0 text-[var(--brand)]" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>{{ __('Download product photos for your posts.') }}</li>
                        @if ($subdomainsEnabled)
                            <li class="flex gap-3"><svg class="h-5 w-5 shrink-0 text-[var(--brand)]" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>{{ __('Choose your own store address, like :example.', ['example' => $subdomainExample]) }}</li>
                        @endif
                        @if ($customDomainsEnabled)
                            <li class="flex gap-3"><svg class="h-5 w-5 shrink-0 text-[var(--brand)]" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>{{ __('Connect a domain you own, like myshop.com.') }}</li>
                        @endif
                    </ul>
                </div>
                @if ($storeLinkExample)
                    <div class="rounded-3xl border border-gray-200 bg-white p-3 shadow-xl shadow-black/5 dark:border-white/10 dark:bg-gray-900">
                        <div class="flex items-center gap-2 rounded-2xl bg-gray-100 px-4 py-3 text-sm dark:bg-white/5">
                            <span class="flex gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-red-400"></span><span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span><span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span></span>
                            <span class="ms-2 truncate text-gray-500 dark:text-gray-400">{{ $subdomainsEnabled ? $subdomainExample : $storeLinkExample }}</span>
                        </div>
                        <div class="grid grid-cols-3 gap-3 p-4">
                            @for ($i = 0; $i < 6; $i++)
                                <div class="rounded-xl border border-gray-100 p-2 dark:border-white/5">
                                    <div class="aspect-square rounded-lg guide-brand-soft"></div>
                                    <div class="mt-2 h-2 w-3/4 rounded bg-gray-200 dark:bg-white/10"></div>
                                    <div class="mt-1.5 h-2 w-1/2 rounded bg-[var(--brand)] opacity-60"></div>
                                </div>
                            @endfor
                        </div>
                    </div>
                @endif
            </div>
        </section>

        {{-- Orders --}}
        <section id="orders" class="scroll-mt-20 border-y border-gray-200/70 bg-white dark:border-white/10 dark:bg-gray-900/40">
            <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
                <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('Orders and delivery') }}</h2>
                <p class="mt-2 text-gray-600 dark:text-gray-400">{{ __('Customers order on your store, or you place an order for them in the portal. You choose how it is delivered.') }}</p>
                <div class="mt-8 grid items-start gap-4 {{ $ownCourierAvailable ? 'lg:grid-cols-2' : '' }}">
                    <article class="rounded-2xl border border-gray-200 p-6 dark:border-white/10">
                        <h3 class="font-semibold">{{ __('The store delivers') }}</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ __('The store packs the parcel and delivers it to your customer. Orders you place for a customer in the portal this way are cash on delivery, with nothing to pay in advance.') }}</p>
                    </article>
                    @if ($ownCourierAvailable)
                        <article class="rounded-2xl border border-gray-200 p-6 dark:border-white/10">
                            <h3 class="font-semibold">{{ __('Your own courier') }}</h3>
                            <ul class="mt-3 space-y-3 text-sm leading-6 text-gray-600 dark:text-gray-400">
                                <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]"></span>{{ __('You pay the store the full amount in advance: your cost, a packaging charge for each piece, and VAT where it applies.') }}</li>
                                @if ($showFigures && $packagingCharge > 0)
                                    <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]"></span>{{ __('The packaging charge is :amount per piece.', ['amount' => $money($packagingCharge)]) }}</li>
                                @endif
                                @if ($paymentMethodNames->isNotEmpty())
                                    <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]"></span>{{ __('Pay by: :methods.', ['methods' => $paymentMethodNames->implode(', ')]) }}</li>
                                @endif
                                <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]"></span>{{ __('The store checks your payment before it packs the parcel and puts your parcel ID on it.') }}</li>
                                <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]"></span>{{ __('Until the payment is checked you can add products, raise quantities and correct the details. Nothing can be removed or lowered, and you pay only the extra.') }}</li>
                                <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]"></span>{{ __('Your customer never sees the store\'s price: their invoice carries your shop name and logo, your prices and the amount they still have to pay.') }}</li>
                                <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]"></span>{{ __('Paid more than needed? The extra goes to your deposit and is used on your next orders automatically. A deposit cannot be withdrawn.') }}</li>
                            </ul>
                        </article>
                    @endif
                </div>
            </div>
        </section>

        {{-- Rules --}}
        <section id="rules" class="mx-auto max-w-4xl scroll-mt-20 px-4 py-16 sm:px-6">
            <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('Rules and regulations') }}</h2>
            <p class="mt-2 text-gray-600 dark:text-gray-400">{{ __('By applying you agree to follow these rules.') }}</p>
            @if ($rules->isNotEmpty())
                <div class="mt-8 space-y-3">
                    @foreach ($rules as $i => $rule)
                        <details class="group rounded-2xl border border-gray-200 bg-white open:shadow-sm dark:border-white/10 dark:bg-gray-900" @if ($i === 0) open @endif>
                            <summary class="flex cursor-pointer list-none items-center gap-4 p-5 [&::-webkit-details-marker]:hidden">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-[var(--brand)] guide-brand-soft">{{ $i + 1 }}</span>
                                <span class="flex-1 font-semibold">{{ $rule['title'] }}</span>
                                <svg class="h-5 w-5 shrink-0 text-gray-400 transition group-open:rotate-180" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                            </summary>
                            @if (filled($rule['body'] ?? null))
                                <div class="whitespace-pre-line px-5 pb-5 ps-[4.25rem] text-sm leading-6 text-gray-600 dark:text-gray-400">{{ $rule['body'] }}</div>
                            @endif
                        </details>
                    @endforeach
                </div>
            @else
                <p class="mt-8 rounded-2xl border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">{{ __('The store has not published extra rules yet. The sections above describe how the program works.') }}</p>
            @endif
        </section>

        {{-- Final call to action --}}
        <section class="mx-auto max-w-6xl px-4 pb-20 sm:px-6">
            <div class="relative overflow-hidden rounded-3xl bg-[var(--brand)] px-6 py-12 text-center text-white sm:px-12">
                <div class="pointer-events-none absolute -right-20 -top-20 h-64 w-64 rounded-full bg-white/10"></div>
                <div class="pointer-events-none absolute -bottom-24 -left-16 h-64 w-64 rounded-full bg-black/10"></div>
                <h2 class="relative text-2xl font-bold sm:text-3xl">{{ __('Ready to start selling?') }}</h2>
                <p class="relative mx-auto mt-3 max-w-xl text-white/85">{{ __('Apply today. Once the store approves you, your store is ready to share.') }}</p>
                <div class="relative mt-8 flex flex-wrap justify-center gap-3">
                    <a class="inline-flex min-h-12 items-center rounded-xl bg-white px-6 text-base font-semibold text-gray-900 shadow-lg transition hover:bg-gray-100" href="{{ $applyUrl }}" data-guide-cta>{{ __('Apply now') }}</a>
                    <a class="inline-flex min-h-12 items-center rounded-xl border border-white/40 px-6 text-base font-semibold text-white transition hover:bg-white/10" href="{{ $loginUrl }}">{{ __('I already have an account') }}</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-gray-200/70 py-8 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
        © {{ now()->year }} {{ $companyName }}
    </footer>
</body>
</html>
