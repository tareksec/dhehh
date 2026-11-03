{{--
    Settings → Email delivery (owner request 2026-11-02): the usage of whichever mail service the site sends
    through. The service is found from the SMTP host (EmailProvider), the numbers come from the Email Log
    (EmailUsageService) in email-delivery-usage.blade.php. Amazon SES keeps its own tools on Settings → Amazon SES.
--}}
<x-filament-panels::page>
    @if (! $this->hasSite())
        <x-filament::section>
            <p class="text-sm">{{ __('This company has no site yet. Add one in Site → Storefront Settings; the email account belongs to a site.') }}</p>
        </x-filament::section>
    @else
        @php
            $provider = $this->provider();
            $site = $this->site();
            $from = app(\App\Services\StorefrontNotificationService::class)->mailFromAddress($site);
            $port = data_get($site->notification_credentials, 'mail_port');
            $fromDomain = $from ? substr(strrchr($from, '@'), 1) : null;
            $trackingOn = $this->trackingOn();
            $setupUrl = $this->emailSetupUrl();
            $sesUrl = $this->amazonSesUrl();
        @endphp

        <div class="zz-ses space-y-6">
            {{ $this->form }}

            {{-- The service, found from the SMTP host --}}
            <x-filament::section>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                            <x-filament::icon icon="heroicon-o-envelope" aria-hidden="true" class="h-6 w-6" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ $provider->name }}</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                @if ($provider->isServerMailer())
                                    {{ __('No SMTP account is saved for this site, so its email goes through the server\'s own mailer. Set one up in Email Setup.') }}
                                @elseif ($provider->key === \App\Services\Email\EmailProvider::OTHER)
                                    {{ __('Found from the SMTP host :host. This service is not on the list below, so only the numbers from the Email Log are shown.', ['host' => $provider->host]) }}
                                @else
                                    {{ __('Found from the SMTP host :host.', ['host' => $provider->host]) }}
                                @endif
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($setupUrl)
                            <x-filament::button tag="a" :href="$setupUrl" color="gray">{{ __('Open Email Setup') }}</x-filament::button>
                        @endif
                        @if ($provider->isAmazonSes() && $sesUrl)
                            <x-filament::button tag="a" :href="$sesUrl" color="gray">{{ __('Open Amazon SES') }}</x-filament::button>
                        @endif
                    </div>
                </div>

                <dl class="mt-4 grid gap-x-6 gap-y-2 border-t border-gray-200 pt-4 text-sm dark:border-white/10 sm:grid-cols-2">
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('SMTP host') }}</dt><dd class="min-w-0 break-all text-right font-medium">{{ $provider->host !== '' ? $provider->host.($port ? ':'.$port : '') : '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('From address') }}</dt><dd class="min-w-0 break-all text-right font-medium">{{ $from ?: '—' }}</dd></div>
                </dl>

                <div class="mt-4 flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <span>{{ __('Recognised services:') }}</span>
                    @foreach (\App\Services\Email\EmailProvider::knownNames() as $known)
                        <x-filament::badge :color="$known === $provider->name ? 'primary' : 'gray'">{{ $known }}</x-filament::badge>
                    @endforeach
                    <x-filament::badge :color="$provider->key === \App\Services\Email\EmailProvider::OTHER ? 'primary' : 'gray'">{{ __('Other SMTP service') }}</x-filament::badge>
                </div>
            </x-filament::section>

            @include('filament.pages.email-delivery-usage')

            {{-- What this service can tell us --}}
            <x-filament::section :heading="__('About this service')" :description="__('Only what :provider can tell us.', ['provider' => $provider->name])">
                <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <li class="flex items-start justify-between gap-3 py-3 first:pt-0">
                        <div class="min-w-0">
                            <p class="font-medium">{{ __('Sending domain') }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $fromDomain ?: __('No From address is set yet.') }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ __('Check its SPF and DKIM records in your provider\'s dashboard.') }}</span>
                    </li>
                    <li class="flex items-start justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="font-medium">{{ __('Bounces and complaints') }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($provider->reportsBack)
                                    {{ $trackingOn ? __('Reported back by Amazon SES, so the Email Log shows Delivered, Bounced and Marked as spam.') : __('Amazon SES can report them back. Turn tracking on in Settings → Amazon SES.') }}
                                @else
                                    {{ __('This service does not report them back to the dashboard.') }}
                                @endif
                            </p>
                        </div>
                        <x-filament::badge :color="$trackingOn ? 'success' : 'gray'">{{ $trackingOn ? __('On') : ($provider->reportsBack ? __('Off') : __('Not available')) }}</x-filament::badge>
                    </li>
                    <li class="flex items-start justify-between gap-3 py-3 last:pb-0">
                        <div class="min-w-0">
                            <p class="font-medium">{{ __('Blocked addresses') }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $provider->isAmazonSes() ? __('Amazon SES keeps a suppression list; manage it in Settings → Amazon SES.') : __('Managed in your provider\'s own dashboard.') }}</p>
                        </div>
                        @if ($provider->isAmazonSes() && $sesUrl)
                            <x-filament::button tag="a" :href="$sesUrl" color="gray" size="sm">{{ __('Open Amazon SES') }}</x-filament::button>
                        @endif
                    </li>
                </ul>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
