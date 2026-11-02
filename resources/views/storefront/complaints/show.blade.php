@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@section('content')
    <section class="border-b border-gray-200 dark:border-white/10">
        <div class="mx-auto w-full max-w-5xl px-4 py-7 sm:px-5 lg:px-6">
            <p class="text-xs font-semibold uppercase tracking-wider text-[var(--storefront-brand)]">{{ __('After-sales support') }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Submit a product complaint') }}</h1>
            <p class="storefront-page-copy mt-3 max-w-3xl">{{ __('If a product is damaged, incorrect, or the delivered quantity does not match, send the details with an order ID, invoice ID, or phone number.') }}</p>
        </div>
    </section>

    <section class="mx-auto grid w-full max-w-5xl gap-6 px-4 py-7 sm:px-5 lg:grid-cols-[minmax(0,1fr)_300px] lg:px-6">
        <div>
            @if (session('complaint_submitted'))
                <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-200" role="status" aria-live="polite">
                    <p class="font-semibold">{{ __('Complaint received successfully.') }}</p>
                    <p class="mt-1">{{ __('Reference:') }} <span class="font-semibold">{{ session('complaint_submitted') }}</span>{{ __('. Please keep this number for follow-up.') }}</p>
                </div>
            @endif

            <form class="rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-white/5" method="POST" action="{{ \App\Support\StorefrontUrl::route('complaints.store', [], $previewSlug ?? null) }}" @if ($errors->any()) aria-describedby="complaint-errors" @endif>
                @csrf

                @if ($errors->any())
                    <div id="complaint-errors" class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200" role="alert" tabindex="-1">
                        <p class="font-semibold">{{ __('Please review the complaint details.') }}</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="block sm:col-span-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Order ID, invoice ID, or order phone number') }}</span>
                        <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm outline-none focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10" name="order_reference" value="{{ old('order_reference', request('order')) }}" required aria-describedby="order-reference-help" @error('order_reference') aria-invalid="true" @enderror>
                        <span id="order-reference-help" class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('Any one of these identifiers is enough to start the complaint.') }}</span>
                        @error('order_reference')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Customer name') }} <span class="text-gray-400">{{ __('(optional)') }}</span></span>
                        <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm outline-none focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10" name="customer_name" autocomplete="name" value="{{ old('customer_name') }}">
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Contact phone') }} <span class="text-gray-400">{{ __('(optional)') }}</span></span>
                        <input class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm outline-none focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10" type="tel" inputmode="tel" name="phone" autocomplete="tel" value="{{ old('phone', request('phone')) }}">
                    </label>

                    <label class="block sm:col-span-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Issue type') }}</span>
                        <select class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm outline-none focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-gray-900" name="issue_type" required>
                            <option value="">{{ __('Select the problem') }}</option>
                            @foreach (\App\Models\StorefrontComplaint::ISSUE_TYPES as $value => $label)
                                <option value="{{ $value }}" @selected(old('issue_type') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('issue_type')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
                    </label>

                    <label class="block sm:col-span-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Complaint details') }}</span>
                        <textarea class="mt-2 min-h-36 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm outline-none focus:border-[var(--storefront-brand)] focus:ring-1 focus:ring-[var(--storefront-brand)] dark:border-white/15 dark:bg-white/10" name="details" minlength="10" maxlength="3000" required>{{ old('details') }}</textarea>
                        @error('details')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
                    </label>
                </div>

                <button class="mt-6 inline-flex min-h-11 items-center justify-center rounded-lg bg-[var(--storefront-brand)] px-6 py-3 text-sm font-medium text-white transition hover:opacity-90 disabled:opacity-50" type="submit">{{ __('Submit complaint') }}</button>
            </form>
        </div>

        <aside class="h-fit rounded-xl border border-gray-200 bg-gray-50 p-5 text-sm dark:border-white/10 dark:bg-white/[0.03]">
            <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('What happens next?') }}</h2>
            <ol class="mt-4 space-y-4 text-gray-600 dark:text-gray-300">
                <li><span class="font-semibold text-gray-900 dark:text-white">{{ __('1. Recorded company-wise') }}</span><br>{{ __('Your complaint gets a unique reference.') }}</li>
                <li><span class="font-semibold text-gray-900 dark:text-white">{{ __('2. Team notification') }}</span><br>{{ __('The configured company Telegram account receives the details.') }}</li>
                <li><span class="font-semibold text-gray-900 dark:text-white">{{ __('3. Resolution tracking') }}</span><br>{{ __('Staff updates the complaint status from the admin panel.') }}</li>
            </ol>
        </aside>
    </section>
@endsection
