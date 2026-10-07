<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\StorefrontCartRecord;
use App\Models\StorefrontMetaAttribution;
use App\Models\StorefrontMetaEvent;
use App\Models\StorefrontSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class StorefrontMetaConversionsService
{
    public function __construct(protected StorefrontMetaTrackingService $tracking) {}

    public function sendPurchase(Order $order, array $context = []): bool
    {
        $order->loadMissing('customer', 'items.product', 'storefrontSetting', 'company.storefrontSetting');
        $setting = $order->siteSetting();

        if (
            ! $setting
            || ! $this->tracking->capiEnabled($setting)
            || ! $this->tracking->serverConsentGranted($setting, $order)
        ) {
            return false;
        }

        $customData = $this->tracking->purchasePayload($order);
        $eventId = $this->tracking->purchaseEventId($order);

        $sent = $this->sendEvent(
            order: $order,
            setting: $setting,
            eventName: 'Purchase',
            eventId: $eventId,
            eventTime: $order->created_at->timestamp,
            actionSource: 'website',
            customData: $customData,
            context: $context,
            payloadSummary: [
                'currency' => $customData['currency'],
                'value' => $customData['value'],
                'content_ids' => $customData['content_ids'],
                'has_email' => filled($order->customer?->email),
                'has_phone' => filled($order->customer?->phone),
                'has_browser_ids' => filled($context['fbp'] ?? null) || filled($context['fbc'] ?? null),
            ],
        );

        if ($sent) {
            StorefrontMetaAttribution::withoutGlobalScopes()
                ->where('company_id', $order->company_id)
                ->where('order_id', $order->getKey())
                ->update([
                    'context' => null,
                    'purchase_dispatched_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return $sent;
    }

    public function sendOrderStatus(OrderStatusTransition $transition): bool
    {
        $transition->loadMissing(['order.customer', 'order.items.product', 'order.storefrontSetting', 'order.company.storefrontSetting']);
        $order = $transition->order;
        $setting = $order?->siteSetting();
        $stage = $this->tracking->statusEventStage($transition);

        if (
            ! $order
            || $order->source !== Order::SOURCE_STOREFRONT
            || ! $setting
            || ! $stage
            || ! $this->tracking->statusEventEnabled($setting, $stage)
            || ! $this->tracking->serverConsentGranted($setting, $order)
        ) {
            return false;
        }

        $customData = [
            ...$this->tracking->purchasePayload($order),
            'workflow_stage' => $stage,
            'order_status' => $transition->to_status,
            'delivery_status' => $transition->to_delivery_status,
            'transition_source' => $transition->source,
        ];
        $eventName = StorefrontMetaTrackingService::STATUS_EVENTS[$stage];

        return $this->sendEvent(
            order: $order,
            setting: $setting,
            eventName: $eventName,
            eventId: $this->tracking->orderStatusEventId($transition, $stage),
            eventTime: $transition->created_at?->timestamp ?? now()->timestamp,
            actionSource: 'system_generated',
            customData: $customData,
            context: [],
            payloadSummary: [
                'currency' => $customData['currency'],
                'value' => $customData['value'],
                'content_ids' => $customData['content_ids'],
                'workflow_stage' => $stage,
                'order_status' => $transition->to_status,
                'delivery_status' => $transition->to_delivery_status,
                'transition_source' => $transition->source,
                'has_email' => filled($order->customer?->email),
                'has_phone' => filled($order->customer?->phone),
                'has_browser_ids' => false,
            ],
            transition: $transition,
        );
    }

    public function sendRecovered(StorefrontCartRecord $cart): bool
    {
        $cart->loadMissing(['recoveredOrder.customer', 'recoveredOrder.items.product', 'recoveredOrder.storefrontSetting', 'recoveredOrder.company.storefrontSetting']);
        $order = $cart->recoveredOrder;
        $setting = $order?->siteSetting();

        if (
            ! $order
            || $order->source !== Order::SOURCE_STOREFRONT
            || ! $setting
            || ! $cart->recovered_at
            || ! $this->tracking->statusEventEnabled($setting, StorefrontMetaTrackingService::RECOVERED_STAGE)
            || ! $this->tracking->serverConsentGranted($setting, $order)
        ) {
            return false;
        }

        $customData = [
            ...$this->tracking->purchasePayload($order),
            'workflow_stage' => StorefrontMetaTrackingService::RECOVERED_STAGE,
            'recovery_channel' => collect($cart->last_reminder_channels)->filter()->implode(',') ?: null,
        ];

        $sent = $this->sendEvent(
            order: $order,
            setting: $setting,
            eventName: 'Recovered',
            eventId: $this->tracking->recoveredEventId($cart, $order),
            eventTime: $cart->recovered_at->timestamp,
            actionSource: 'system_generated',
            customData: array_filter($customData, static fn ($value): bool => $value !== null),
            context: [],
            payloadSummary: [
                'currency' => $customData['currency'],
                'value' => $customData['value'],
                'content_ids' => $customData['content_ids'],
                'workflow_stage' => StorefrontMetaTrackingService::RECOVERED_STAGE,
                'recovery_channel' => $customData['recovery_channel'],
                'has_email' => filled($order->customer?->email),
                'has_phone' => filled($order->customer?->phone),
                'has_browser_ids' => false,
            ],
            cart: $cart,
        );

        if ($sent) {
            StorefrontMetaAttribution::withoutGlobalScopes()
                ->where('company_id', $order->company_id)
                ->where('order_id', $order->getKey())
                ->update(['recovered_dispatched_at' => now(), 'updated_at' => now()]);
        }

        return $sent;
    }

    protected function sendEvent(
        Order $order,
        StorefrontSetting $setting,
        string $eventName,
        string $eventId,
        int $eventTime,
        string $actionSource,
        array $customData,
        array $context,
        array $payloadSummary,
        ?OrderStatusTransition $transition = null,
        ?StorefrontCartRecord $cart = null,
    ): bool {
        $allSent = true;

        foreach ($this->tracking->capiPixels($setting) as $pixel) {
            $audit = StorefrontMetaEvent::withoutGlobalScopes()->firstOrCreate(
                [
                    'company_id' => $order->company_id,
                    'pixel_id' => $pixel['pixel_id'],
                    'event_id' => $eventId,
                ],
                [
                    'order_id' => $order->getKey(),
                    'order_status_transition_id' => $transition?->getKey(),
                    'storefront_cart_record_id' => $cart?->getKey(),
                    'event_name' => $eventName,
                    'status' => StorefrontMetaEvent::STATUS_PENDING,
                    'payload_summary' => $payloadSummary,
                ],
            );

            if ($audit->status === StorefrontMetaEvent::STATUS_SENT) {
                continue;
            }

            $audit->increment('attempts');

            $serverEvent = array_filter([
                'event_name' => $eventName,
                'event_time' => $eventTime,
                'event_id' => $eventId,
                'action_source' => $actionSource,
                'event_source_url' => $context['event_source_url'] ?? null,
                'user_data' => $this->userData($order, $context),
                'custom_data' => $customData,
            ], static fn ($value): bool => $value !== null);
            $payload = [
                'data' => [$serverEvent],
                'access_token' => $pixel['access_token'],
            ];

            if ($pixel['test_event_code'] !== '') {
                $payload['test_event_code'] = $pixel['test_event_code'];
            }

            try {
                $response = Http::asJson()
                    ->acceptJson()
                    ->timeout(10)
                    ->retry(2, 250, throw: false)
                    ->post($this->endpoint($pixel['pixel_id']), $payload);

                if (! $response->successful() || (int) ($response->json('events_received') ?? 0) < 1) {
                    throw new RuntimeException($this->safeError($response->json('error.message'), $pixel['access_token']) ?: "Meta returned HTTP {$response->status()}.");
                }

                $audit->update([
                    'status' => StorefrontMetaEvent::STATUS_SENT,
                    'response_summary' => array_filter([
                        'events_received' => (int) $response->json('events_received'),
                        'fbtrace_id' => $response->json('fbtrace_id'),
                    ], static fn ($value): bool => filled($value)),
                    'error' => null,
                    'sent_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                $allSent = false;
                $audit->update([
                    'status' => StorefrontMetaEvent::STATUS_FAILED,
                    'response_summary' => null,
                    'error' => $this->safeError($exception->getMessage(), $pixel['access_token']) ?: 'Meta event delivery failed.',
                ]);
            }
        }

        return $allSent;
    }

    protected function userData(Order $order, array $context): array
    {
        $email = Str::lower(trim((string) $order->customer?->email));
        $phone = preg_replace('/\D+/', '', (string) $order->customer?->phone) ?: '';

        if (str_starts_with($phone, '0')) {
            $phone = '88'.$phone;
        }

        return array_filter([
            'em' => $email !== '' ? [hash('sha256', $email)] : null,
            'ph' => $phone !== '' ? [hash('sha256', $phone)] : null,
            'client_ip_address' => $context['client_ip_address'] ?? null,
            'client_user_agent' => $context['client_user_agent'] ?? null,
            'fbp' => $context['fbp'] ?? null,
            'fbc' => $context['fbc'] ?? null,
            'external_id' => [hash('sha256', "customer:{$order->company_id}:{$order->customer_id}")],
        ], static fn ($value): bool => filled($value));
    }

    protected function endpoint(string $pixelId): string
    {
        $version = trim((string) config('services.meta.graph_api_version', 'v25.0'), '/');

        return "https://graph.facebook.com/{$version}/{$pixelId}/events";
    }

    protected function safeError(mixed $message, string $accessToken = ''): string
    {
        $message = is_string($message) ? $message : '';
        $message = preg_replace('/access_token=[^&\s]+/i', 'access_token=[redacted]', $message) ?: '';

        if ($accessToken !== '') {
            $message = str_replace($accessToken, '[redacted]', $message);
        }

        return Str::limit($message, 1000, '');
    }
}
