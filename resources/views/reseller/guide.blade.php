@php
    use App\Support\MoneyFormatter;

    $money = fn (float $amount): string => MoneyFormatter::currency($amount, $currency);
    $steps = [
        ['title' => __('Apply'), 'body' => __('Fill in your name, phone, business name and a password. It takes about a minute.'), 'color' => 'nb-yellow'],
        ['title' => __('Get approved'), 'body' => __('The store reviews your application. Sign in with the same phone and password to see its status.'), 'color' => 'nb-pink'],
        ['title' => __('Set up your store'), 'body' => __('Pick the products for your store, set your own prices and share your store link.'), 'color' => 'nb-mint'],
        ['title' => __('Earn on every sale'), 'body' => __('You earn the difference between your price and your cost on every delivered order.'), 'color' => 'nb-sky'],
    ];
    $check = '<svg class="h-5 w-5 shrink-0" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>';
    $arrow = '<svg class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>';
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
    @vite(['resources/css/app.css', 'resources/css/reseller-landing.css'])
    <style>
        :root { --brand: {{ $brandColor }}; --on-brand: {{ $onBrandColor }}; }
    </style>
</head>
<body class="nb min-h-screen antialiased" data-reseller-guide>
    <header class="nb-border-b nb-paper sticky top-0 z-30" style="background: var(--nb-card)">
        <div class="mx-auto flex h-[4.5rem] max-w-6xl items-center justify-between gap-3 px-4 sm:px-6">
            <a class="flex min-w-0 items-center gap-3" href="#top">
                @if ($logoUrl)
                    <img class="h-9 w-auto max-w-32 object-contain" src="{{ $logoUrl }}" alt="{{ $companyName }}">
                @else
                    <span class="nb-display truncate text-base">{{ $companyName }}</span>
                @endif
                <span class="hidden sm:block"><span class="nb-chip nb-yellow">{{ __('Reseller guide') }}</span></span>
            </a>
            <nav class="flex shrink-0 items-center gap-2 sm:gap-3">
                <a class="nb-btn nb-btn--light nb-btn--small" href="{{ $loginUrl }}">{{ __('Sign in') }}</a>
                <a class="nb-btn nb-btn--small" href="{{ $applyUrl }}" data-guide-cta>{{ __('Apply now') }}</a>
            </nav>
        </div>
    </header>

    <main id="top">
        {{-- Hero --}}
        <section class="nb-dots nb-border-b relative overflow-hidden">
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-14 sm:px-6 sm:py-20 lg:grid-cols-[1.15fr_0.85fr]">
                <div>
                    <p class="nb-chip nb-pink">{{ __('Reseller program') }}</p>
                    <h1 class="nb-display mt-5" style="font-size: clamp(1.9rem, 5.2vw, 3.5rem)">
                        {{ __('Sell :company products from your own store.', ['company' => $companyName]) }}
                    </h1>
                    <p class="mt-6 max-w-xl text-lg leading-8" style="font-weight: 500">
                        {{ filled($intro) ? $intro : __('No stock to buy and no warehouse to run. You choose the products, set your own prices and share your store; the store keeps the stock. Read how it works and the rules below before you apply.') }}
                    </p>
                    <div class="mt-9 flex flex-wrap gap-4">
                        <a class="nb-btn" href="{{ $applyUrl }}" data-guide-cta>
                            {{ __('Apply now') }}
                            {!! $arrow !!}
                        </a>
                        <a class="nb-btn nb-btn--light" href="#rules">{{ __('Read the rules') }}</a>
                    </div>
                    <nav class="mt-10 flex flex-wrap gap-2.5" aria-label="{{ __('On this page') }}">
                        @foreach (array_filter([
                            'how' => __('How it works'),
                            'money' => __('Pricing and profit'),
                            'store' => __('Your store'),
                            'orders' => __('Orders and delivery'),
                            'rules' => __('Rules'),
                        ]) as $anchor => $label)
                            <a class="nb-chip" href="#{{ $anchor }}">{{ $label }}</a>
                        @endforeach
                    </nav>
                </div>

                {{-- The one idea of the program, as a card: price minus cost is the reseller's profit. --}}
                <div class="relative mx-auto w-full max-w-sm pb-8 pr-6 pt-6 lg:max-w-none" aria-hidden="true">
                    <div class="nb-box nb-mint absolute inset-0 translate-x-6 translate-y-6 -rotate-3"></div>
                    <div class="nb-box nb-yellow relative rotate-2 p-7 sm:p-8">
                        <p class="text-sm font-bold uppercase tracking-wide">{{ __('My profit') }}</p>
                        <p class="nb-display mt-2" style="font-size: clamp(2.6rem, 6vw, 4rem)">A − B</p>
                        <div class="nb-border mt-6 rounded-xl p-4 text-base font-bold" style="background: #ffffff; color: #111111">
                            <div class="flex justify-between"><span>{{ __('Your price') }}</span><span>A</span></div>
                            <div class="mt-1 flex justify-between"><span>{{ __('Your cost') }}</span><span>− B</span></div>
                        </div>
                    </div>
                    <span class="nb-sticker nb-pink -right-1 top-0 h-16 w-16 rotate-12 rounded-full text-3xl" style="color: #111111">✦</span>
                    <span class="nb-sticker nb-brand bottom-1 left-2 h-12 w-12 -rotate-6 rounded-xl text-xl">{!! $check !!}</span>
                </div>
            </div>
        </section>

        {{-- The four steps, as a ticker --}}
        <div class="nb-marquee nb-border-b nb-yellow" aria-hidden="true">
            <div class="nb-marquee__track">
                @for ($copy = 0; $copy < 4; $copy++)
                    @foreach ($steps as $step)
                        <span class="nb-marquee__item">{{ $step['title'] }}</span>
                    @endforeach
                @endfor
            </div>
        </div>

        {{-- How it works --}}
        <section id="how" class="mx-auto max-w-6xl scroll-mt-24 px-4 py-16 sm:px-6 sm:py-20">
            <h2 class="nb-h2">{{ __('How it works') }}</h2>
            <p class="mt-3 text-lg">{{ __('Four steps from applying to your first profit.') }}</p>
            <ol class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($steps as $i => $step)
                    <li class="nb-box nb-lift {{ $step['color'] }} flex flex-col p-6 {{ $i % 2 ? 'lg:translate-y-4' : '' }}">
                        <span class="nb-num" style="background: #111111; color: #ffffff">{{ $i + 1 }}</span>
                        <h3 class="mt-5 text-xl font-bold leading-tight">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-base leading-7">{{ $step['body'] }}</p>
                    </li>
                @endforeach
            </ol>
        </section>

        {{-- Money --}}
        <section id="money" class="nb-sky nb-border-y scroll-mt-24">
            <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
                <h2 class="nb-h2">{{ __('Pricing and profit') }}</h2>
                <div class="mt-10 grid gap-6 lg:grid-cols-3">
                    <article class="nb-box nb-lift p-6">
                        <h3 class="text-xl font-bold">{{ __('Your price, your profit') }}</h3>
                        <p class="mt-3 text-base leading-7">{{ __('Every product shows your cost in the portal. You can sell at your cost or any price above it; the difference is your profit.') }}</p>
                        <div class="nb-border nb-yellow mt-6 rounded-xl p-4 text-base font-bold" style="color: #111111">
                            <div class="flex justify-between"><span>{{ __('Your price') }}</span><span>A</span></div>
                            <div class="mt-1 flex justify-between"><span>{{ __('Your cost') }}</span><span>− B</span></div>
                            <div class="mt-3 flex justify-between border-t-[3px] border-[#111111] pt-3 text-lg"><span>{{ __('My profit') }}</span><span>A − B</span></div>
                        </div>
                    </article>
                    <article class="nb-box nb-lift p-6">
                        <h3 class="text-xl font-bold">{{ __('When you earn it') }}</h3>
                        <ul class="mt-4 space-y-4 text-base leading-7">
                            <li class="flex gap-3"><span class="nb-mint nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('Your profit waits while the order is on its way and becomes yours to withdraw once the order is delivered.') }}</li>
                            <li class="flex gap-3"><span class="nb-mint nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('Cancelled, returned or refunded orders earn no profit. If a delivered order is returned later, its profit is taken back.') }}</li>
                            @if ($returnCharge > 0)
                                <li class="flex gap-3"><span class="nb-mint nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('When an order is returned, a return charge is taken from your deposit, once for that order. This applies to orders the store delivers and to your own-courier orders alike.') }}</li>
                                @if ($showFigures)
                                    <li class="flex gap-3"><span class="nb-mint nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('The return charge is :amount for each returned order.', ['amount' => $money($returnCharge)]) }}</li>
                                @endif
                            @endif
                        </ul>
                    </article>
                    <article class="nb-box nb-lift p-6">
                        <h3 class="text-xl font-bold">{{ __('Withdrawing your money') }}</h3>
                        <ul class="mt-4 space-y-4 text-base leading-7">
                            <li class="flex gap-3"><span class="nb-mint nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('Request a withdrawal from your available balance to bKash, Nagad, Rocket or a bank account.') }}</li>
                            <li class="flex gap-3"><span class="nb-mint nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('The store reviews the request and pays it; you see its status in the portal.') }}</li>
                            @if ($showFigures && $minWithdrawal > 0)
                                <li class="flex gap-3"><span class="nb-mint nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('The smallest withdrawal is :amount.', ['amount' => $money($minWithdrawal)]) }}</li>
                            @endif
                        </ul>
                    </article>
                </div>
            </div>
        </section>

        {{-- Store --}}
        <section id="store" class="mx-auto max-w-6xl scroll-mt-24 px-4 py-16 sm:px-6 sm:py-20">
            <div class="grid items-center gap-12 lg:grid-cols-2">
                <div>
                    <h2 class="nb-h2">{{ __('Your own store') }}</h2>
                    <p class="mt-4 text-lg leading-8">{{ __('Every approved reseller gets an online store with only the products they picked, at their prices. Your customers see your shop name on it, not the store\'s.') }}</p>
                    <ul class="mt-7 space-y-4 text-base">
                        <li class="flex gap-3"><span class="nb-pink nb-border mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('Share your store or any product on WhatsApp and Facebook from the portal.') }}</li>
                        <li class="flex gap-3"><span class="nb-pink nb-border mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('Download product photos for your posts.') }}</li>
                        @if ($subdomainsEnabled)
                            <li class="flex gap-3"><span class="nb-pink nb-border mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('Choose your own store address, like :example.', ['example' => $subdomainExample]) }}</li>
                        @endif
                        @if ($customDomainsEnabled)
                            <li class="flex gap-3"><span class="nb-pink nb-border mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('Connect a domain you own, like myshop.com.') }}</li>
                        @endif
                    </ul>
                </div>
                @if ($storeLinkExample)
                    <div class="nb-box rotate-1 p-0">
                        <div class="nb-border-b nb-orange flex items-center gap-3 rounded-t-[13px] px-4 py-3 text-sm font-bold" style="color: #111111">
                            <span class="flex gap-1.5"><span class="nb-border h-3 w-3 rounded-full bg-white"></span><span class="nb-border h-3 w-3 rounded-full bg-white"></span><span class="nb-border h-3 w-3 rounded-full bg-white"></span></span>
                            <span class="ms-1 truncate">{{ $subdomainsEnabled ? $subdomainExample : $storeLinkExample }}</span>
                        </div>
                        <div class="grid grid-cols-3 gap-4 p-5">
                            @foreach (['nb-yellow', 'nb-pink', 'nb-mint', 'nb-sky', 'nb-orange', 'nb-brand'] as $tile)
                                <div>
                                    <div class="nb-border {{ $tile }} aspect-square rounded-lg"></div>
                                    <div class="mt-2.5 h-2.5 w-3/4 rounded-full" style="background: var(--nb-ink)"></div>
                                    <div class="mt-1.5 h-2.5 w-1/2 rounded-full" style="background: var(--nb-ink); opacity: .35"></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </section>

        {{-- Orders --}}
        <section id="orders" class="nb-mint nb-border-y scroll-mt-24">
            <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
                <h2 class="nb-h2">{{ __('Orders and delivery') }}</h2>
                <p class="mt-3 max-w-3xl text-lg">{{ __('Customers order on your store, or you place an order for them in the portal. You choose how it is delivered.') }}</p>
                <div class="mt-10 grid items-start gap-6 {{ $ownCourierAvailable ? 'lg:grid-cols-2' : '' }}">
                    <article class="nb-box nb-lift p-6">
                        <h3 class="text-xl font-bold">{{ __('The store delivers') }}</h3>
                        <p class="mt-3 text-base leading-7">{{ __('The store packs the parcel and delivers it to your customer. Orders you place for a customer in the portal this way are cash on delivery, with nothing to pay in advance.') }}</p>
                    </article>
                    @if ($ownCourierAvailable)
                        <article class="nb-box nb-lift p-6">
                            <h3 class="text-xl font-bold">{{ __('Your own courier') }}</h3>
                            <ul class="mt-4 space-y-4 text-base leading-7">
                                <li class="flex gap-3"><span class="nb-yellow nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('You pay the store the full amount in advance: your cost, a packaging charge for each piece, and VAT where it applies.') }}</li>
                                @if ($showFigures && $packagingCharge > 0)
                                    <li class="flex gap-3"><span class="nb-yellow nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('The packaging charge is :amount per piece.', ['amount' => $money($packagingCharge)]) }}</li>
                                @endif
                                @if ($paymentMethodNames->isNotEmpty())
                                    <li class="flex gap-3"><span class="nb-yellow nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('Pay by: :methods.', ['methods' => $paymentMethodNames->implode(', ')]) }}</li>
                                @endif
                                <li class="flex gap-3"><span class="nb-yellow nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('The store checks your payment before it packs the parcel and puts your parcel ID on it.') }}</li>
                                <li class="flex gap-3"><span class="nb-yellow nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('Until the payment is checked you can add products, raise quantities and correct the details. Nothing can be removed or lowered, and you pay only the extra.') }}</li>
                                <li class="flex gap-3"><span class="nb-yellow nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('Your customer never sees the store\'s price: their invoice carries your shop name and logo, your prices and the amount they still have to pay.') }}</li>
                                <li class="flex gap-3"><span class="nb-yellow nb-border mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style="color: #111111">{!! $check !!}</span>{{ __('Paid more than needed? The extra goes to your deposit and is used on your next orders automatically. A deposit cannot be withdrawn.') }}</li>
                            </ul>
                        </article>
                    @endif
                </div>
            </div>
        </section>

        {{-- Rules --}}
        <section id="rules" class="mx-auto max-w-4xl scroll-mt-24 px-4 py-16 sm:px-6 sm:py-20">
            <h2 class="nb-h2">{{ __('Rules and regulations') }}</h2>
            <p class="mt-3 text-lg">{{ __('By applying you agree to follow these rules.') }}</p>
            @if ($rules->isNotEmpty())
                <div class="mt-10 space-y-5">
                    @foreach ($rules as $i => $rule)
                        <details class="nb-box nb-faq" @if ($i === 0) open @endif>
                            <summary class="flex items-center gap-4 p-5">
                                <span class="nb-num shrink-0" style="width: 2.5rem; height: 2.5rem; font-size: 1rem">{{ $i + 1 }}</span>
                                <span class="flex-1 text-lg font-bold leading-snug">{{ $rule['title'] }}</span>
                                <span class="nb-faq__icon nb-yellow nb-border inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" style="color: #111111">
                                    <svg class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                                </span>
                            </summary>
                            @if (filled($rule['body'] ?? null))
                                <div class="whitespace-pre-line px-5 pb-6 ps-[4.75rem] text-base leading-7">{{ $rule['body'] }}</div>
                            @endif
                        </details>
                    @endforeach
                </div>
            @else
                <p class="nb-box mt-10 p-6 text-base">{{ __('The store has not published extra rules yet. The sections above describe how the program works.') }}</p>
            @endif
        </section>

        {{-- Final call to action --}}
        <section class="mx-auto max-w-6xl px-4 pb-20 sm:px-6 sm:pb-24">
            <div class="nb-box nb-brand relative overflow-hidden px-6 py-14 text-center sm:px-12" style="box-shadow: 10px 10px 0 var(--nb-ink)">
                <span class="nb-border nb-yellow pointer-events-none absolute -left-8 -top-8 h-20 w-20 rounded-full sm:h-28 sm:w-28"></span>
                <span class="nb-border nb-pink pointer-events-none absolute -bottom-10 -right-6 h-20 w-20 rotate-12 rounded-2xl sm:h-32 sm:w-32"></span>
                <h2 class="nb-display relative mx-auto max-w-3xl pt-4 sm:pt-0" style="font-size: clamp(1.7rem, 4.4vw, 3rem)">{{ __('Ready to start selling?') }}</h2>
                <p class="relative mx-auto mt-4 max-w-xl text-lg" style="opacity: .92">{{ __('Apply today. Once the store approves you, your store is ready to share.') }}</p>
                <div class="relative mt-9 flex flex-wrap justify-center gap-4">
                    <a class="nb-btn nb-btn--yellow" href="{{ $applyUrl }}" data-guide-cta>{{ __('Apply now') }} {!! $arrow !!}</a>
                    <a class="nb-btn nb-btn--light" href="{{ $loginUrl }}">{{ __('I already have an account') }}</a>
                </div>
            </div>
        </section>
    </main>

    <div class="nb-border-t">
        @if ($footer)
            @include('reseller.partials.footer', ['footer' => $footer])
        @else
            <footer class="py-8 text-center text-sm font-bold" style="background: var(--nb-card)">
                © {{ now()->year }} {{ $companyName }}
            </footer>
        @endif
    </div>
</body>
</html>
