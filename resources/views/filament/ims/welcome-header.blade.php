{{--
    The home pages' heading (IMS-V2/DESIGN.md §6.1): "Welcome back," and the
    name in a lighter tone, today's date in a white pill and the one main
    button on the right. Shared by the office home and the investor portal home.
    With $app (the office): on phones and tablets it becomes the app greeting of
    the owner's reference — initials, "Good morning!" and the name — and the
    main button moves to the "+" in the bottom bar.
--}}
<header @class(['ims-welcome', 'ims-welcome-app' => $app ?? false])>
    @if ($app ?? false)
        <x-ims.avatar :name="$name" size="md" class="ims-welcome-avatar" />
    @endif
    <div class="ims-welcome-text">
        @isset($greeting)
            <p class="ims-welcome-greeting">{{ $greeting }}</p>
        @endisset
        <h1 class="ims-welcome-heading">
            <span class="ims-welcome-lead">{{ __('Welcome back,') }}</span> <span class="ims-welcome-name">{{ $name }}</span>
        </h1>
    </div>

    <div class="ims-welcome-actions">
        <span class="ims-date-pill">
            <x-filament::icon icon="heroicon-o-calendar" class="h-5 w-5" />
            {{ $date }}
        </span>
        @if ($action)
            <a href="{{ $action['url'] }}" class="ims-btn ims-btn-white">
                <x-filament::icon :icon="$action['icon']" class="h-5 w-5" />
                {{ $action['label'] }}
            </a>
        @endif
    </div>
</header>
