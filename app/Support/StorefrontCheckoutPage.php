<?php

namespace App\Support;

use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * What a storefront checkout page needs to decide before it draws anything:
 * the payment choices and the default one, whether ordering is blocked,
 * the weight-based delivery charges and the signed-in customer's details.
 * Every theme's checkout.show reads them from here so a theme only changes
 * the design; the rules themselves stay in StorefrontCheckoutService.
 */
final class StorefrontCheckoutPage
{
    /**
     * @param  Collection<int, StorefrontPaymentMethod>  $paymentMethods  Dashboard-managed list: any number of manual channels, at most one COD row and one online gateway row.
     * @param  Collection<int, StorefrontPaymentMethod>  $manualPaymentMethods
     */
    private function __construct(
        public readonly Collection $paymentMethods,
        public readonly string $defaultPaymentMethod,
        public readonly bool $hasPaymentPath,
        public readonly Collection $manualPaymentMethods,
        public readonly ?StorefrontPaymentMethod $onlineGatewayMethod,
        public readonly bool $preorderPaymentBlocked,
        public readonly bool $blocked,
        public readonly string $blockDescription,
        public readonly float $insideCharge,
        public readonly float $outsideCharge,
        public readonly float $actualWeight,
        public readonly int $billedWeight,
        public readonly ?Authenticatable $customer,
        public readonly ?string $autosaveUrl,
    ) {}

    /**
     * @param  Collection<int, StorefrontPaymentMethod>  $paymentMethods
     * @param  array{fee: float|int|string, weight: float|int|string, billed_weight: int|string}  $insideQuote
     * @param  array{fee: float|int|string}  $outsideQuote
     */
    public static function make(
        StorefrontSetting $setting,
        Collection $paymentMethods,
        float $advanceDue,
        bool $onlinePaymentAvailable,
        array $insideQuote,
        array $outsideQuote,
        ?string $previewSlug = null,
        ?string $oldPaymentMethod = null,
    ): self {
        $paymentMethodValues = $paymentMethods->map->paymentValue()->all();
        $hasPaymentPath = $paymentMethods->isNotEmpty();
        $preorderPaymentBlocked = $advanceDue > 0 && ! $onlinePaymentAvailable;

        return new self(
            paymentMethods: $paymentMethods,
            defaultPaymentMethod: in_array($oldPaymentMethod, $paymentMethodValues, true)
                ? $oldPaymentMethod
                : ($paymentMethodValues[0] ?? ''),
            hasPaymentPath: $hasPaymentPath,
            manualPaymentMethods: $paymentMethods->where('type', StorefrontPaymentMethod::TYPE_MANUAL),
            onlineGatewayMethod: $paymentMethods->firstWhere('type', StorefrontPaymentMethod::TYPE_ONLINE_GATEWAY),
            preorderPaymentBlocked: $preorderPaymentBlocked,
            blocked: ! $hasPaymentPath || $preorderPaymentBlocked,
            blockDescription: implode(' ', array_filter([
                ! $hasPaymentPath ? 'checkout-payment-unavailable' : null,
                $preorderPaymentBlocked ? 'checkout-preorder-payment-unavailable' : null,
            ])),
            insideCharge: (float) $insideQuote['fee'],
            outsideCharge: (float) $outsideQuote['fee'],
            actualWeight: (float) $insideQuote['weight'],
            billedWeight: (int) $insideQuote['billed_weight'],
            customer: auth('customer')->user(),
            autosaveUrl: ($setting->checkout_autosave_enabled ?? false)
                ? StorefrontUrl::route('checkout.autosave', [], $previewSlug)
                : null,
        );
    }
}
