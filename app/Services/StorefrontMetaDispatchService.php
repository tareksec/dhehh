<?php

namespace App\Services;

use App\Jobs\SendStorefrontMetaOrderStatusJob;
use App\Jobs\SendStorefrontMetaPurchaseJob;
use App\Jobs\SendStorefrontMetaRecoveredJob;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\StorefrontCartRecord;
use App\Models\StorefrontMetaAttribution;
use App\Models\StorefrontSetting;
use Illuminate\Support\Facades\Schema;

class StorefrontMetaDispatchService
{
    public function __construct(protected StorefrontMetaTrackingService $tracking) {}

    public function captureOrder(
        Order $order,
        StorefrontSetting $setting,
        array $context,
        ?float $courierSuccessRatio = null,
        ?int $cartRecordId = null,
    ): ?StorefrontMetaAttribution {
        if (
            $order->source !== Order::SOURCE_STOREFRONT
            || $context === []
            || ! Schema::hasTable('storefront_meta_attributions')
        ) {
            return null;
        }

        $cart = $cartRecordId
            ? StorefrontCartRecord::withoutGlobalScopes()
                ->where('company_id', $order->company_id)
                ->find($cartRecordId)
            : null;
        $recovered = (bool) ($cart?->recovered_at || $cart?->reminded_at || $cart?->recovery_opened_at);
        $dueStage = $this->tracking->purchaseDispatchStage($setting, $courierSuccessRatio);

        $attribution = StorefrontMetaAttribution::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $order->company_id, 'order_id' => $order->getKey()],
            [
                'context' => $context,
                'purchase_due_stage' => $dueStage,
                'consent_granted' => (bool) ($context['consent_granted'] ?? false),
                'recovered' => $recovered,
                'storefront_cart_record_id' => $cart?->getKey(),
            ],
        );

        if ($this->tracking->capiEnabled($setting) && $dueStage === StorefrontMetaAttribution::DUE_IMMEDIATE) {
            SendStorefrontMetaPurchaseJob::dispatch($order->getKey())->afterCommit();
        }

        if (
            $cart
            && $recovered
            && $this->tracking->statusEventEnabled($setting, StorefrontMetaTrackingService::RECOVERED_STAGE)
        ) {
            SendStorefrontMetaRecoveredJob::dispatch($cart->getKey())->afterCommit();
        }

        return $attribution;
    }

    public function dispatchForTransition(OrderStatusTransition $transition): void
    {
        // An order sent back to an earlier stage is a correction, not a new event for Meta.
        if ($transition->isStepBack()) {
            return;
        }

        $order = Order::withoutGlobalScopes()
            ->with('storefrontSetting', 'company.storefrontSetting')
            ->find($transition->order_id);
        $setting = $order?->siteSetting();

        if (! $order || ! $setting || $order->source !== Order::SOURCE_STOREFRONT) {
            return;
        }

        // "Completed" is reached either via the explicit Completed stage or
        // via Delivered (both set status to STATUS_COMPLETED) — either path
        // should satisfy the "only after order status shows Completed"
        // Purchase timing, matching what the order actually displays.
        $purchaseDueStageForTransitionStage = match (true) {
            $transition->stage === Order::STAGE_CONFIRMED => StorefrontMetaAttribution::DUE_CONFIRMED,
            $order->status === Order::STATUS_COMPLETED => StorefrontMetaAttribution::DUE_COMPLETED,
            default => null,
        };

        if (
            $purchaseDueStageForTransitionStage !== null
            && Schema::hasTable('storefront_meta_attributions')
            && StorefrontMetaAttribution::withoutGlobalScopes()
                ->where('company_id', $order->company_id)
                ->where('order_id', $order->getKey())
                ->where('purchase_due_stage', $purchaseDueStageForTransitionStage)
                ->whereNull('purchase_dispatched_at')
                ->exists()
            && $this->tracking->capiEnabled($setting)
        ) {
            SendStorefrontMetaPurchaseJob::dispatch($order->getKey())->afterCommit();
        }

        $stage = $this->tracking->statusEventStage($transition);

        if (
            $stage
            && $this->tracking->statusEventEnabled($setting, $stage)
            && $this->tracking->serverConsentGranted($setting, $order)
        ) {
            SendStorefrontMetaOrderStatusJob::dispatch($transition->getKey())->afterCommit();
        }
    }
}
