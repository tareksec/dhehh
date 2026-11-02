<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StorefrontPayment;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Services\OfferPricingService;
use App\Services\StorefrontDeliveryAreaResolver;
use App\Services\StorefrontDeliveryService;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Separate, standalone checkout for an offer landing page — one product
 * line (single or exploded combo), not the multi-item cart/checkout flow.
 * Does not touch CheckoutController or StorefrontCart in any way.
 */
class OfferCheckoutController extends Controller
{
    public function __construct(
        protected CompanyContext $context,
        protected OfferPricingService $pricing,
        protected CustomerAccountService $accounts,
        protected StorefrontDeliveryService $delivery,
        protected StorefrontDeliveryAreaResolver $deliveryAreas,
    ) {}

    public function store(Request $request, string $slug): RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->storeForCompany($request, $company, $setting, $slug);
    }

    public function storePreview(Request $request, Company $company, string $slug): RedirectResponse
    {
        $setting = $this->previewStorefront($company);

        return $this->storeForCompany($request, $company, $setting, $slug, $company->slug);
    }

    public function thankYou(Request $request, string $slug, Order $order): View
    {
        [$company, $setting] = $this->domainStorefront($request);

        abort_unless($order->company_id === $company->getKey() && $order->source === Order::SOURCE_OFFER, 404);

        $customer = auth('customer')->user();
        $customerOwnsOrder = $customer instanceof Customer
            && $customer->company_id === $company->getKey()
            && $customer->getKey() === $order->customer_id;

        abort_unless($request->hasValidSignature() || $customerOwnsOrder, 404);

        return $this->thankYouView($company, $setting, $slug, $order);
    }

    public function thankYouPreview(Request $request, Company $company, string $slug, Order $order): View
    {
        $setting = $this->previewStorefront($company);

        abort_unless($order->company_id === $company->getKey() && $order->source === Order::SOURCE_OFFER, 404);

        // The preview has no signed URL, so the order ID alone would expose
        // a customer's name, phone and address to any signed-in staff member.
        // Require the same permission as opening the order in the admin panel.
        abort_unless(! auth()->check() || auth()->user()->canPerformModelAbility('view', Order::class), 404);

        return $this->thankYouView($company, $setting, $slug, $order, $company->slug);
    }

    protected function storeForCompany(Request $request, Company $company, StorefrontSetting $setting, string $slug, ?string $previewSlug = null): RedirectResponse
    {
        $offer = Offer::query()->landingPages()->where('slug', trim($slug))->where('status', Offer::STATUS_PUBLISHED)->firstOrFail();
        $data = $this->validatedCheckout($request, $offer);
        $quantity = (int) $data['quantity'];

        $this->pricing->assertInStock($offer, $quantity);

        $deliveryArea = $this->deliveryAreas->resolve($data['address'], $setting);
        $lines = $this->pricing->explodeToOrderLines($offer, $quantity);
        $deliveryItems = $offer->items->map(fn (OfferItem $item): array => [
            'product' => $item->product,
            'quantity' => $item->quantity * $quantity,
        ]);
        $quote = $this->delivery->quote($deliveryItems, $deliveryArea, $setting);

        $vat = $setting->vatAmountForItems(collect($lines)->map(fn (array $line): array => [
            'subtotal' => (float) $line['unit_price'] * (int) $line['quantity'],
            'product' => $offer->items->firstWhere('product_id', $line['product_id'])?->product,
        ]));

        [$order, $generatedPassword] = DB::transaction(function () use ($company, $offer, $data, $lines, $quote, $deliveryArea, $vat): array {
            $customer = Customer::query()
                ->where('company_id', $company->getKey())
                ->where('phone', $data['phone'])
                ->lockForUpdate()
                ->first();

            $isNewAccount = ! $customer?->isRegistered();
            $generatedPassword = $isNewAccount ? Str::password(12) : null;

            if ($isNewAccount) {
                $customer = $this->accounts->register([
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'email' => $data['email'],
                    'address' => $data['address'],
                    'password' => $generatedPassword,
                ]);
            }

            $order = Order::query()->create([
                'customer_id' => $customer->getKey(),
                'customer_name' => $customer->name,
                'order_date' => now()->toDateString(),
                'discount' => 0,
                'vat' => $vat,
                'shipping_zone' => $deliveryArea,
                'shipping_fee' => $quote['fee'],
                'paid_amount' => 0,
                'status' => 'draft',
                'source' => Order::SOURCE_OFFER,
                'note' => "Offer landing page order: {$offer->title} (#{$offer->getKey()})",
            ]);

            foreach ($lines as $line) {
                OrderItem::query()->create(['order_id' => $order->getKey(), ...$line]);
            }

            $order->refresh();

            if (in_array($data['payment_method'], ['manual_bkash', 'manual_nagad'], true)) {
                StorefrontPayment::query()->create([
                    'company_id' => $company->getKey(),
                    'order_id' => $order->getKey(),
                    'gateway' => $data['payment_method'],
                    'amount' => round((float) $order->total_amount, 2),
                    'status' => StorefrontPayment::STATUS_PENDING,
                    'payment_method' => $data['sender_number'],
                    'transaction_id' => $data['trx_id'],
                ]);
            }

            return [$order, $generatedPassword];
        });

        // Shown exactly once on the thank-you page via a one-time session
        // flash — never placed in the URL, which the redirect target uses
        // as a signed link so only this browser (or the resulting logged-in
        // customer) can open it.
        session()->flash('offer_checkout_password', $generatedPassword);

        $thankYouUrl = $previewSlug
            ? route('storefront.preview.offers.thank-you', [$previewSlug, $offer->slug, $order])
            : URL::temporarySignedRoute('storefront.offers.thank-you', now()->addDay(), ['slug' => $offer->slug, 'order' => $order->getRouteKey()]);

        return redirect()->to($thankYouUrl);
    }

    protected function thankYouView(Company $company, StorefrontSetting $setting, string $slug, Order $order, ?string $previewSlug = null): View
    {
        $offer = Offer::query()->landingPages()->where('slug', trim($slug))->first();

        return StorefrontThemeRegistry::page('offers.thank-you', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $previewSlug,
            'offer' => $offer,
            'order' => $order->load('items.product', 'customer'),
            'generatedPassword' => session('offer_checkout_password'),
        ]);
    }

    protected function validatedCheckout(Request $request, Offer $offer): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['required', 'string', 'max:1000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'payment_method' => ['required', 'in:cod,manual_bkash,manual_nagad'],
            'sender_number' => ['required_if:payment_method,manual_bkash,manual_nagad', 'nullable', 'string', 'max:20'],
            'trx_id' => ['required_if:payment_method,manual_bkash,manual_nagad', 'nullable', 'string', 'max:40'],
        ]);

        if ($offer->online_payment_required && $data['payment_method'] === 'cod') {
            throw ValidationException::withMessages([
                'payment_method' => __('This offer requires an advance payment — Cash on Delivery is not available for it.'),
            ]);
        }

        $data['phone'] = trim($data['phone']);
        $data['email'] = $data['email'] ?? null;

        return $data;
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

        // Matches Storefront\PreviewController::previewCompany() — an
        // authenticated preview request must belong to a company the
        // signed-in staff member actually has access to (super admins pass
        // via the existing canAccessCompany() bypass).
        if (auth()->check()) {
            abort_unless(auth()->user()->canAccessCompany($company->getKey()), 404);
        }

        $this->context->set($company);

        return $company->storefrontSetting ?: new StorefrontSetting([
            'company_id' => $company->getKey(),
            'theme_color' => '#F59E0B',
            'meta_title' => $company->name,
            'is_published' => true,
        ]);
    }
}
