<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StorefrontCustomerActivity;
use App\Models\StorefrontPayment;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\StorefrontCart;
use App\Services\StorefrontCheckoutService;
use App\Services\StorefrontCustomerActivityService;
use App\Services\StorefrontDeliveryAreaResolver;
use App\Services\StorefrontDeliveryService;
use App\Services\StorefrontMetaTrackingService;
use App\Services\StorefrontPaymentService;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * The Blade storefront's checkout. The rules themselves (delivery, VAT,
 * checkout policy, advances, online payment, order creation) live in
 * StorefrontCheckoutService, shared with the headless Next.js API; this class
 * only does what is specific to the session cart, redirects and views.
 */
class CheckoutController extends Controller
{
    public function __construct(
        protected CompanyContext $context,
        protected StorefrontCart $cart,
        protected StorefrontCheckoutService $checkout,
        protected StorefrontCustomerActivityService $activities,
        protected StorefrontDeliveryService $delivery,
        protected StorefrontDeliveryAreaResolver $deliveryAreas,
        protected StorefrontPaymentService $payments,
        protected StorefrontMetaTrackingService $metaTracking,
    ) {}

    /**
     * Advance amount payable online for pre-order lines. Kept as a delegate:
     * the rule lives in StorefrontCheckoutService::advanceDue().
     */
    public static function advanceDue($items): float
    {
        return StorefrontCheckoutService::advanceDue($items);
    }

    public function show(Request $request): View|RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->checkoutView($company, $setting);
    }

    public function showPreview(Company $company): View|RedirectResponse
    {
        $setting = $this->previewStorefront($company);

        return $this->checkoutView($company, $setting, $company->slug);
    }

    public function store(Request $request): RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        $items = $this->cart->items($company);
        $data = $this->validatedCheckout($request, $setting);

        $outcome = $this->checkout->process(
            $company,
            $setting,
            $request,
            $items,
            $data,
            fn (StorefrontPayment $payment): array => [
                URL::temporarySignedRoute('storefront.checkout.payment-return', now()->addHour(), ['payment' => $payment]),
                route('storefront.checkout.show'),
            ],
            cart: $this->cart,
            metaContext: $this->metaTracking->requestContext($request, $setting),
        );

        if (! $outcome->placed()) {
            return redirect()->away($outcome->paymentUrl);
        }

        $order = $outcome->order;
        $successUrl = $this->signedSuccessUrl($order);
        $customer = auth('customer')->user();

        if ($customer instanceof Customer && $customer->getKey() === $order->customer_id) {
            $this->activities->record(
                $customer,
                StorefrontCustomerActivity::TYPE_ORDER_PLACED,
                'Order placed',
                "Order {$order->order_number} was submitted successfully.",
                ['order_number' => $order->order_number, 'total_amount' => (float) $order->total_amount],
            );
        }

        return redirect()->to($successUrl);
    }

    public function storePreview(Request $request, Company $company): RedirectResponse
    {
        $setting = $this->previewStorefront($company);
        $items = $this->cart->items($company);
        $data = $this->validatedCheckout($request, $setting);

        $outcome = $this->checkout->process(
            $company,
            $setting,
            $request,
            $items,
            $data,
            fn (StorefrontPayment $payment): array => [
                URL::temporarySignedRoute('storefront.preview.checkout.payment-return', now()->addHour(), ['company' => $company->slug, 'payment' => $payment]),
                route('storefront.preview.checkout.show', $company->slug),
            ],
            cart: $this->cart,
        );

        if (! $outcome->placed()) {
            return redirect()->away($outcome->paymentUrl);
        }

        return redirect()->route('storefront.preview.checkout.success', [$company->slug, $outcome->order]);
    }

    public function autosave(Request $request): Response
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->persistAutosave($request, $company, $setting);
    }

    public function autosavePreview(Request $request, Company $company): Response
    {
        $setting = $this->previewStorefront($company);

        return $this->persistAutosave($request, $company, $setting);
    }

    protected function persistAutosave(Request $request, Company $company, StorefrontSetting $setting): Response
    {
        if (! (bool) $setting->checkout_autosave_enabled) {
            return response()->noContent();
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->cart->rememberCheckout($company, $data, $this->cart->subtotal($company));

        return response()->noContent();
    }

    public function success(Request $request, Order $order): View
    {
        [$company, $setting] = $this->domainStorefront($request);

        abort_unless($order->company_id === $company->getKey() && $order->source === Order::SOURCE_STOREFRONT, 404);

        $customer = auth('customer')->user();
        $customerOwnsOrder = $customer
            && $customer->company_id === $company->getKey()
            && $customer->getKey() === $order->customer_id;

        abort_unless($request->hasValidSignature() || $customerOwnsOrder, 404);

        return StorefrontThemeRegistry::page('checkout.success', [
            'company' => $company,
            'setting' => $setting,
            'order' => $order->load('items.product', 'customer'),
        ]);
    }

    protected function signedSuccessUrl(Order $order): string
    {
        return URL::temporarySignedRoute(
            'storefront.checkout.success',
            now()->addDay(),
            ['order' => $order->getRouteKey()],
        );
    }

    public function successPreview(Company $company, Order $order): View
    {
        $setting = $this->previewStorefront($company);

        abort_unless($order->company_id === $company->getKey() && $order->source === Order::SOURCE_STOREFRONT, 404);

        return StorefrontThemeRegistry::page('checkout.success', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $company->slug,
            'order' => $order->load('items.product', 'customer'),
        ]);
    }

    public function paymentReturn(Request $request, StorefrontPayment $payment): RedirectResponse
    {
        // PayStation appends invoice_number/trx_id onto this signed URL
        // when it redirects the browser back — those weren't part of the
        // originally-signed query string, so a plain hasValidSignature()
        // would reject every real PayStation return. Ignoring just those
        // param names is a no-op for ZiniPay (which never appends them).
        // status/cancel are ignored defensively for gateway cancel/timeout
        // redirects that may append their own extra params.
        if (! $request->hasValidSignatureWhileIgnoring(['invoice_number', 'trx_id', 'status', 'cancel'])) {
            Log::info('Storefront payment return had an invalid/expired signature — redirecting to checkout.', [
                'payment' => $payment->getKey(),
            ]);

            return redirect()->route('storefront.checkout.show')
                ->withErrors(['payment' => 'Your payment session ended or was cancelled. Please try again.']);
        }

        [$company, $setting] = $this->domainStorefront($request);

        return $this->completePaymentReturn($payment, $company, $setting, transactionId: $request->query('trx_id'));
    }

    public function paymentReturnPreview(Request $request, Company $company, StorefrontPayment $payment): RedirectResponse
    {
        if (! $request->hasValidSignatureWhileIgnoring(['invoice_number', 'trx_id', 'status', 'cancel'])) {
            Log::info('Storefront preview payment return had an invalid/expired signature — redirecting to checkout.', [
                'payment' => $payment->getKey(),
            ]);

            return redirect()->route('storefront.preview.checkout.show', $company->slug)
                ->withErrors(['payment' => 'Your payment session ended or was cancelled. Please try again.']);
        }

        $setting = $this->previewStorefront($company);

        return $this->completePaymentReturn($payment, $company, $setting, $company->slug, $request->query('trx_id'));
    }

    protected function completePaymentReturn(
        StorefrontPayment $payment,
        Company $company,
        StorefrontSetting $setting,
        ?string $previewSlug = null,
        ?string $transactionId = null,
    ): RedirectResponse {
        abort_unless($payment->company_id === $company->getKey(), 404);

        try {
            $order = $this->payments->verifyAndFinalize($payment, $setting, transactionId: $transactionId);
        } catch (\Throwable $exception) {
            Log::warning('Storefront payment return could not be finalized', [
                'payment' => $payment->getKey(),
                'error' => $exception->getMessage(),
            ]);

            $order = null;
        }

        if (! $order) {
            return redirect($previewSlug
                ? route('storefront.preview.checkout.show', $previewSlug)
                : route('storefront.checkout.show'))
                ->withErrors(['payment' => 'Payment was not completed or could not be verified. No order was placed.']);
        }

        $this->cart->clear($company, $order);

        return $previewSlug
            ? redirect()->route('storefront.preview.checkout.success', [$previewSlug, $order])
            : redirect()->to($this->signedSuccessUrl($order));
    }

    protected function checkoutView(Company $company, StorefrontSetting $setting, ?string $previewSlug = null): View|RedirectResponse
    {
        $items = $this->cart->items($company);

        if ($items->isEmpty()) {
            return redirect($previewSlug
                ? route('storefront.preview.cart.show', $previewSlug)
                : route('storefront.cart.show'))->with('storefront_status', 'Add products before checkout.');
        }

        $insideQuote = $this->delivery->quote($items, 'inside', $setting);
        $outsideQuote = $this->delivery->quote($items, 'outside', $setting);
        $loggedInCustomer = auth('customer')->user();

        if ((bool) $setting->checkout_autosave_enabled) {
            $this->cart->startCheckout($company, $this->cart->subtotal($company), [
                'name' => $loggedInCustomer?->name,
                'phone' => $loggedInCustomer?->phone,
                'email' => $loggedInCustomer?->email,
                'address' => $loggedInCustomer?->address,
            ]);
        }

        return StorefrontThemeRegistry::page('checkout.show', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $previewSlug,
            'items' => $items,
            'subtotal' => $this->cart->subtotal($company),
            'vatAmount' => $setting->vatAmountForItems($items),
            'vatIncluded' => $setting->vatIncludedInItems($items),
            'advanceDue' => StorefrontCheckoutService::advanceDue($items),
            'onlinePaymentAvailable' => $this->checkout->onlinePaymentAvailable($setting),
            'paymentMethods' => $this->checkout->activePaymentMethods($setting),
            'insideQuote' => $insideQuote,
            'outsideQuote' => $outsideQuote,
            'insideDhakaKeywords' => $this->deliveryAreas->keywords($setting),
            'outsideDhakaMarkers' => $this->deliveryAreas->outsideMarkers(),
        ]);
    }

    protected function validatedCheckout(Request $request, StorefrontSetting $setting): array
    {
        $methods = $this->checkout->activePaymentMethods($setting);

        if (! $request->filled('payment_method') && $methods->isNotEmpty()) {
            $request->merge(['payment_method' => $methods->first()->paymentValue()]);
        }

        $data = $request->validate($this->checkout->contactRules($methods, (string) $request->input('payment_method')));

        return $this->checkout->normalizeContact($data);
    }

    protected function domainStorefront(Request $request): array
    {
        $company = $request->attributes->get('storefront_company');

        abort_unless($company instanceof Company && $company->storefrontSetting?->is_published, 404);

        $this->context->set($company);

        return [$company, $company->storefrontSetting];
    }

    protected function previewStorefront(Company $company): StorefrontSetting
    {
        abort_unless(app()->environment(['local', 'testing']) || auth()->check(), 404);

        $this->context->set($company);

        return $company->storefrontSetting ?: new StorefrontSetting([
            'company_id' => $company->getKey(),
            'theme_color' => '#F59E0B',
            'meta_title' => $company->name,
            'is_published' => true,
        ]);
    }
}
