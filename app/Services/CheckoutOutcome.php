<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StorefrontCheckoutAttempt;
use App\Models\StorefrontPayment;

/**
 * What a checkout ended in: either a placed Order, or a hosted payment the
 * customer must complete first (the Order is created only after the gateway
 * verifies it — see StorefrontOrderPlacementService).
 */
final class CheckoutOutcome
{
    /** @param array<string, mixed> $decision the advance decision (see StorefrontPaymentEligibilityService) */
    public function __construct(
        public readonly ?Order $order = null,
        public readonly ?StorefrontPayment $payment = null,
        public readonly ?string $paymentUrl = null,
        public readonly array $decision = [],
        public readonly ?StorefrontCheckoutAttempt $attempt = null,
    ) {}

    public function placed(): bool
    {
        return $this->order !== null;
    }
}
