<?php

namespace App\Services;

use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\StorefrontCartRecord;
use App\Models\StorefrontMetaAttribution;
use App\Models\StorefrontSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StorefrontMetaTrackingService
{
    public const RECOVERED_STAGE = 'recovered';

    public const STATUS_EVENTS = [
        self::RECOVERED_STAGE => 'Recovered',
        Order::STAGE_CONFIRMED => 'Confirmed',
        Order::STAGE_PROCESSING => 'Processing',
        Order::STAGE_SHIPPING => 'Shipping',
        Order::STAGE_DELIVERED => 'Delivered',
        Order::STAGE_COMPLETED => 'Completed',
        Order::STAGE_CANCELLED => 'Cancelled',
        Order::STAGE_RETURNED => 'Returned',
        Order::STAGE_REFUNDED => 'Refunded',
    ];

    public const DEFAULT_STATUS_EVENTS = [
        self::RECOVERED_STAGE,
        Order::STAGE_CONFIRMED,
        Order::STAGE_PROCESSING,
        Order::STAGE_SHIPPING,
        Order::STAGE_DELIVERED,
        Order::STAGE_COMPLETED,
        Order::STAGE_CANCELLED,
        Order::STAGE_RETURNED,
        Order::STAGE_REFUNDED,
    ];

    public const BROWSER_EVENTS = [
        'PageView' => 'Page view',
        'ViewContent' => 'Product viewed',
        'AddToCart' => 'Added to cart',
        'InitiateCheckout' => 'Checkout started',
        'Purchase' => 'Purchase',
        'ViewCart' => 'Cart viewed',
        'RemoveFromCart' => 'Removed from cart',
        'ViewCategory' => 'Category viewed',
        'ViewItemList' => 'Product list viewed',
        'Search' => 'Catalog searched',
        'CompleteRegistration' => 'Account registration completed',
    ];

    public const DEFAULT_BROWSER_EVENTS = [
        'PageView',
        'ViewContent',
        'AddToCart',
        'InitiateCheckout',
        'Purchase',
        'ViewCart',
        'RemoveFromCart',
        'ViewCategory',
        'ViewItemList',
        'Search',
        'CompleteRegistration',
    ];

    public const CUSTOM_EVENT_TYPES = [
        'link' => 'Link click',
        'click' => 'Element click',
        'scroll' => 'Element becomes visible',
        'time' => 'Time on page',
    ];

    public const PURCHASE_TIMINGS = [
        'immediate' => 'Immediately after checkout',
        'risk_aware' => 'Immediately for trusted courier history; otherwise after confirmation',
        'confirmed' => 'Only after order confirmation',
        'completed' => 'Only after order status shows Completed',
    ];

    public function browserEnabled(StorefrontSetting $setting, ?Request $request = null): bool
    {
        return (bool) $setting->meta_tracking_enabled
            && (bool) $setting->meta_browser_tracking_enabled
            && $this->browserPixelIds($setting) !== []
            && $this->consentGranted($setting, $request);
    }

    public function capiEnabled(StorefrontSetting $setting): bool
    {
        return (bool) $setting->meta_tracking_enabled
            && (bool) $setting->meta_capi_enabled
            && $this->capiPixels($setting) !== [];
    }

    public function consentCookieName(StorefrontSetting $setting): string
    {
        return 'storefront_meta_consent_'.$setting->company_id;
    }

    public function consentStatus(StorefrontSetting $setting, ?Request $request = null): ?string
    {
        if (! $setting->meta_consent_required) {
            return 'granted';
        }

        $value = $request?->cookie($this->consentCookieName($setting));

        return in_array($value, ['granted', 'denied'], true) ? $value : null;
    }

    public function consentGranted(StorefrontSetting $setting, ?Request $request = null): bool
    {
        return $this->consentStatus($setting, $request) === 'granted';
    }

    public function browserEventEnabled(StorefrontSetting $setting, string $eventName, ?Request $request = null): bool
    {
        if (! $this->browserEnabled($setting, $request)) {
            return false;
        }

        $events = is_array($setting->meta_browser_events) && $setting->meta_browser_events !== []
            ? $setting->meta_browser_events
            : self::DEFAULT_BROWSER_EVENTS;

        return array_key_exists($eventName, self::BROWSER_EVENTS) && in_array($eventName, $events, true);
    }

    public function pixelConfigurations(StorefrontSetting $setting): array
    {
        $credentials = is_array($setting->meta_tracking_credentials) ? $setting->meta_tracking_credentials : [];
        $pixels = [];

        if ($this->validPixelId($setting->meta_pixel_id)) {
            $pixels[(string) $setting->meta_pixel_id] = [
                'pixel_id' => (string) $setting->meta_pixel_id,
                'access_token' => trim((string) ($credentials['access_token'] ?? '')),
                'test_event_code' => trim((string) ($credentials['test_event_code'] ?? '')),
            ];
        }

        foreach ((array) ($credentials['additional_pixels'] ?? []) as $pixel) {
            if (! is_array($pixel) || ! $this->validPixelId($pixel['pixel_id'] ?? null)) {
                continue;
            }

            $pixelId = (string) $pixel['pixel_id'];
            $pixels[$pixelId] = [
                'pixel_id' => $pixelId,
                'access_token' => trim((string) ($pixel['access_token'] ?? '')),
                'test_event_code' => trim((string) ($pixel['test_event_code'] ?? '')),
            ];
        }

        return array_values($pixels);
    }

    public function browserPixelIds(StorefrontSetting $setting): array
    {
        return array_column($this->pixelConfigurations($setting), 'pixel_id');
    }

    public function capiPixels(StorefrontSetting $setting): array
    {
        return array_values(array_filter(
            $this->pixelConfigurations($setting),
            static fn (array $pixel): bool => $pixel['access_token'] !== '',
        ));
    }

    public function domainVerificationTags(StorefrontSetting $setting): array
    {
        return collect($setting->meta_domain_verification_tags)
            ->map(fn ($tag): string => trim((string) (is_array($tag) ? ($tag['content'] ?? '') : $tag)))
            ->filter(fn (string $tag): bool => preg_match('/^[A-Za-z0-9_-]{8,255}$/', $tag) === 1)
            ->unique()
            ->values()
            ->all();
    }

    public function customEvents(StorefrontSetting $setting): array
    {
        if (! $setting->meta_custom_events_enabled) {
            return [];
        }

        return collect($setting->meta_custom_events)
            ->filter(fn ($event): bool => is_array($event))
            ->map(function (array $event): ?array {
                $type = (string) ($event['type'] ?? '');
                $name = trim((string) ($event['event_name'] ?? ''));
                $selector = trim((string) ($event['selector'] ?? ''));
                $seconds = max(1, min(3600, (int) ($event['seconds'] ?? 1)));

                if (
                    ! array_key_exists($type, self::CUSTOM_EVENT_TYPES)
                    || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $name) !== 1
                    || $selector === ''
                    || mb_strlen($selector) > 200
                ) {
                    return null;
                }

                return array_filter([
                    'type' => $type,
                    'event_name' => $name,
                    'selector' => $selector,
                    'seconds' => $type === 'time' ? $seconds : null,
                ], static fn ($value): bool => $value !== null);
            })
            ->filter()
            ->values()
            ->all();
    }

    public function advancedMatchingData(?Customer $customer): array
    {
        if (! $customer) {
            return [];
        }

        $email = Str::lower(trim((string) $customer->email));
        $phone = $this->normalizePhone($customer->phone);
        $names = preg_split('/\s+/', Str::lower(trim((string) $customer->name)), 2) ?: [];

        return array_filter([
            'em' => $email !== '' ? hash('sha256', $email) : null,
            'ph' => $phone !== '' ? hash('sha256', $phone) : null,
            'fn' => filled($names[0] ?? null) ? hash('sha256', $names[0]) : null,
            'ln' => filled($names[1] ?? null) ? hash('sha256', $names[1]) : null,
            'external_id' => hash('sha256', "customer:{$customer->company_id}:{$customer->getKey()}"),
        ], static fn ($value): bool => filled($value));
    }

    public function purchaseDispatchStage(StorefrontSetting $setting, ?float $courierSuccessRatio): string
    {
        $timing = array_key_exists((string) $setting->meta_purchase_timing, self::PURCHASE_TIMINGS)
            ? (string) $setting->meta_purchase_timing
            : StorefrontMetaAttribution::DUE_IMMEDIATE;

        if ($timing === 'confirmed') {
            return StorefrontMetaAttribution::DUE_CONFIRMED;
        }

        if ($timing === 'completed') {
            return StorefrontMetaAttribution::DUE_COMPLETED;
        }

        if ($timing !== 'risk_aware' || $courierSuccessRatio === null) {
            return StorefrontMetaAttribution::DUE_IMMEDIATE;
        }

        $threshold = max(0, min(100, (int) $setting->meta_purchase_success_ratio_threshold));

        return $courierSuccessRatio >= $threshold
            ? StorefrontMetaAttribution::DUE_IMMEDIATE
            : StorefrontMetaAttribution::DUE_CONFIRMED;
    }

    public function shouldFireBrowserPurchase(StorefrontSetting $setting, Order $order, ?Request $request = null): bool
    {
        if (! $this->browserEventEnabled($setting, 'Purchase', $request)) {
            return false;
        }

        if (! Schema::hasTable('storefront_meta_attributions')) {
            return true;
        }

        $attribution = StorefrontMetaAttribution::withoutGlobalScopes()
            ->where('company_id', $order->company_id)
            ->where('order_id', $order->getKey())
            ->first();

        return ! $attribution || $attribution->purchase_due_stage === StorefrontMetaAttribution::DUE_IMMEDIATE;
    }

    public function serverConsentGranted(StorefrontSetting $setting, Order $order): bool
    {
        if (! $setting->meta_consent_required) {
            return true;
        }

        if (! Schema::hasTable('storefront_meta_attributions')) {
            return false;
        }

        return StorefrontMetaAttribution::withoutGlobalScopes()
            ->where('company_id', $order->company_id)
            ->where('order_id', $order->getKey())
            ->where('consent_granted', true)
            ->exists();
    }

    public function eventId(string $prefix): string
    {
        return Str::lower($prefix).'-'.Str::uuid();
    }

    public function purchaseEventId(Order $order): string
    {
        return "purchase-{$order->company_id}-{$order->getKey()}";
    }

    public function orderStatusEventId(OrderStatusTransition $transition, string $stage): string
    {
        return "status-{$stage}-{$transition->company_id}-{$transition->order_id}-{$transition->getKey()}";
    }

    public function recoveredEventId(StorefrontCartRecord $cart, Order $order): string
    {
        return "recovered-{$order->company_id}-{$order->getKey()}-{$cart->getKey()}";
    }

    public function statusEventEnabled(StorefrontSetting $setting, string $stage): bool
    {
        if (! $this->capiEnabled($setting) || ! $setting->meta_status_events_enabled) {
            return false;
        }

        $enabled = $setting->meta_status_events;
        $enabled = is_array($enabled) && $enabled !== [] ? $enabled : self::DEFAULT_STATUS_EVENTS;

        return array_key_exists($stage, self::STATUS_EVENTS) && in_array($stage, $enabled, true);
    }

    public function statusEventStage(OrderStatusTransition $transition): ?string
    {
        if (array_key_exists((string) $transition->stage, self::STATUS_EVENTS)) {
            return $transition->stage;
        }

        if ($transition->to_status !== $transition->from_status) {
            return match ($transition->to_status) {
                Order::STATUS_CONFIRMED => Order::STAGE_CONFIRMED,
                Order::STATUS_PROCESSING => $this->shippingDeliveryStatus($transition->to_delivery_status)
                    ? Order::STAGE_SHIPPING
                    : Order::STAGE_PROCESSING,
                Order::STATUS_COMPLETED => $transition->to_delivery_status === CourierBooking::STATUS_DELIVERED
                    ? Order::STAGE_DELIVERED
                    : Order::STAGE_COMPLETED,
                Order::STATUS_CANCELLED => Order::STAGE_CANCELLED,
                Order::STATUS_RETURNED => Order::STAGE_RETURNED,
                Order::STATUS_REFUNDED => Order::STAGE_REFUNDED,
                default => null,
            };
        }

        if ($this->shippingDeliveryStatus($transition->to_delivery_status)) {
            return Order::STAGE_SHIPPING;
        }

        return match ($transition->to_delivery_status) {
            CourierBooking::STATUS_DELIVERED => Order::STAGE_DELIVERED,
            CourierBooking::STATUS_RETURNED => Order::STAGE_RETURNED,
            default => null,
        };
    }

    public function requestContext(Request $request, StorefrontSetting $setting): array
    {
        if (! $this->consentGranted($setting, $request)) {
            return [];
        }

        return array_filter([
            'client_ip_address' => $request->ip(),
            'client_user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'fbp' => $this->validMetaCookie($request->cookie('_fbp')),
            'fbc' => $this->validMetaCookie($request->cookie('_fbc')),
            'event_source_url' => $request->fullUrl(),
            'consent_granted' => true,
        ], static fn ($value): bool => filled($value));
    }

    /**
     * The same tracking context requestContext() captures from a browser
     * request, for the headless Next.js site: its server forwards what it
     * read from the visitor (cookies, IP, user agent). Honours the company's
     * consent setting — when consent is required, nothing is captured unless
     * the visitor granted it on the website.
     *
     * @param  array<string, mixed>  $meta  fbp, fbc, event_source_url, consent_granted
     */
    public function contextFromClient(StorefrontSetting $setting, array $meta, ?string $ip, ?string $userAgent): array
    {
        if ($setting->meta_consent_required && ($meta['consent_granted'] ?? false) !== true) {
            return [];
        }

        $sourceUrl = (string) ($meta['event_source_url'] ?? '');

        return array_filter([
            'client_ip_address' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
            'client_user_agent' => Str::limit((string) $userAgent, 500, ''),
            'fbp' => $this->validMetaCookie($meta['fbp'] ?? null),
            'fbc' => $this->validMetaCookie($meta['fbc'] ?? null),
            'event_source_url' => preg_match('#^https?://#i', $sourceUrl) === 1 ? Str::limit($sourceUrl, 500, '') : null,
            'consent_granted' => true,
        ], static fn ($value): bool => filled($value));
    }

    public function purchasePayload(Order $order): array
    {
        $order->loadMissing('items.product');

        return [
            'content_ids' => $order->items->map(fn ($item): string => (string) $item->product_id)->unique()->values()->all(),
            'contents' => $order->items->map(fn ($item): array => [
                'id' => (string) $item->product_id,
                'quantity' => (int) $item->quantity,
                'item_price' => (float) $item->unit_price,
            ])->values()->all(),
            'content_type' => 'product',
            'currency' => 'BDT',
            'value' => round((float) $order->total_amount, 2),
            'order_id' => (string) $order->order_number,
        ];
    }

    protected function validPixelId(mixed $pixelId): bool
    {
        return is_string($pixelId) && preg_match('/^\d{5,32}$/', $pixelId) === 1;
    }

    protected function normalizePhone(mixed $value): string
    {
        $phone = preg_replace('/\D+/', '', (string) $value) ?: '';

        if (str_starts_with($phone, '0')) {
            $phone = '88'.$phone;
        }

        return $phone;
    }

    protected function validMetaCookie(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^fb\.[0-9]+\.[0-9]+\.[A-Za-z0-9_-]+$/', $value)) {
            return null;
        }

        return Str::limit($value, 255, '');
    }

    protected function shippingDeliveryStatus(?string $status): bool
    {
        return in_array($status, [
            CourierBooking::STATUS_PICKED_UP,
            CourierBooking::STATUS_IN_TRANSIT,
            CourierBooking::STATUS_PARTIAL_DELIVERED,
        ], true);
    }
}
