<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\CourierReturn;
use App\Models\CourierStatusLog;
use App\Models\Order;
use App\Support\MoneyFormatter;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CourierService
{
    public function manualProvider(?Company $company = null): CourierProvider
    {
        $company ??= app(CompanyContext::class)->company();
        $company ??= Company::defaultCompany();

        if (! $company) {
            throw ValidationException::withMessages([
                'company' => 'A company is required before creating a courier booking.',
            ]);
        }

        return CourierProvider::query()->firstOrCreate(
            [
                'company_id' => $company->getKey(),
                'slug' => 'manual',
            ],
            [
                'name' => 'Manual Courier',
                'driver' => CourierProvider::DRIVER_MANUAL,
                'credentials' => [],
                'settings' => [],
                'is_active' => true,
            ],
        );
    }

    /**
     * The line under the COD field of every booking popup: grand total minus
     * the advance already received, so staff see why the courier is asked to
     * collect less than the invoice total.
     */
    public function codHelperText(Order $order): string
    {
        $currency = $order->company?->currency ?: 'BDT';

        return __('Grand total :total − advance paid :paid = :due. This is what the courier collects from the customer.', [
            'total' => MoneyFormatter::currency($order->courierInvoiceTotal(), $currency),
            'paid' => MoneyFormatter::currency((float) $order->paid_amount, $currency),
            'due' => MoneyFormatter::currency($order->courierCodAmount(), $currency),
        ]);
    }

    /**
     * Default state for the "Book courier" / "Book Steadfast" popups
     * (ViewOrder + OrdersTable). Applied via `->fillForm()` rather than
     * per-field `->default()` because Filament skips a component's default
     * whenever a `->fillForm()` payload is present but omits that field, and
     * so the recipient + COD prefill lives in exactly one place.
     *
     * Every field a form relies on a default for must be listed here for its
     * shape — `manual` = ViewOrder's "Book courier", `steadfast` = ViewOrder's
     * "Book Steadfast", `unified` = OrdersTable's row "Book courier".
     *
     * @return array<string, mixed>
     */
    public function bookingFormDefaults(Order $order, string $shape = 'unified'): array
    {
        $order->loadMissing('customer');

        $common = [
            'recipient_name' => $order->customer_name ?: $order->customer?->name,
            'recipient_phone' => $order->customer?->phone,
            'recipient_address' => $order->customer?->address,
            'cod_amount' => $order->courierCodAmount(),
            'note' => $this->courierNote($order),
        ];

        return match ($shape) {
            'manual' => $common,
            'steadfast' => [
                ...$common,
                'recipient_email' => $order->customer?->email,
                'delivery_type' => 0,
            ],
            default => [
                ...$common,
                'courier_provider_id' => $order->courier_provider_id
                    ?? CourierProvider::defaultForCompany($order->company_id)?->getKey(),
                'sf_recipient_email' => $order->customer?->email,
                'sf_delivery_type' => 0,
                'ph_delivery_type' => 48,
                'ph_item_weight' => 0.5,
                'rx_parcel_weight' => 500,
            ],
        };
    }

    public function createManualBooking(Order $order, array $data = []): CourierBooking
    {
        $order->loadMissing('customer', 'company');
        app(CustomerRiskService::class)->assertNotBlacklisted($order);

        if (! $order->company_id) {
            throw ValidationException::withMessages([
                'order' => 'The order must belong to a company before booking courier.',
            ]);
        }

        $provider = filled($data['courier_provider_id'] ?? null)
            ? CourierProvider::query()->whereKey($data['courier_provider_id'])->firstOrFail()
            : $this->manualProvider($order->company);

        if ($provider->driver !== CourierProvider::DRIVER_MANUAL) {
            throw ValidationException::withMessages([
                'provider' => 'Please select a custom/manual courier provider.',
            ]);
        }

        if ((int) $provider->company_id !== (int) $order->company_id) {
            throw ValidationException::withMessages([
                'provider' => 'The courier provider must belong to the same company as the order.',
            ]);
        }

        $status = filled($data['tracking_id'] ?? null)
            ? CourierBooking::STATUS_BOOKED
            : CourierBooking::STATUS_BOOKING_PENDING;

        $booking = CourierBooking::query()->create([
            'company_id' => $order->company_id,
            'courier_provider_id' => $provider->getKey(),
            'order_id' => $order->getKey(),
            'tracking_id' => $data['tracking_id'] ?? $this->manualTrackingId($order),
            'recipient_name' => $data['recipient_name'] ?? $order->customer_name ?? $order->customer?->name ?? 'Customer',
            'recipient_phone' => $data['recipient_phone'] ?? $order->customer?->phone,
            'recipient_address' => $data['recipient_address'] ?? $order->customer?->address,
            'cod_amount' => $data['cod_amount'] ?? $order->courierCodAmount(),
            'status' => $status,
            'booked_at' => now(),
            'note' => $this->courierNote($order, $data['note'] ?? null),
        ]);

        $this->logStatus($booking, null, $status, $data['note'] ?? 'Manual courier booking created.');
        $this->syncOrderDeliveryStatus($order, $status, $data['note'] ?? 'Manual courier booking created.');
        $this->applyMargin($booking, $order, $provider);

        return $booking;
    }

    public function createSteadfastBooking(Order $order, CourierProvider $provider, array $data = []): CourierBooking
    {
        $order->loadMissing(['customer', 'items.product', 'company']);
        app(CustomerRiskService::class)->assertNotBlacklisted($order);

        if ($provider->driver !== CourierProvider::DRIVER_STEADFAST) {
            throw ValidationException::withMessages([
                'provider' => 'Please select a Steadfast courier provider.',
            ]);
        }

        if ((int) $provider->company_id !== (int) $order->company_id) {
            throw ValidationException::withMessages([
                'provider' => 'The courier provider must belong to the same company as the order.',
            ]);
        }

        $payload = $this->steadfastPayload($order, $data);
        $response = $this->callCourier('Steadfast', fn (): array => app(SteadfastCourierClient::class)->createOrder($provider, $payload));
        $consignment = $response['consignment'] ?? [];

        if (($response['status'] ?? null) !== 200 || blank($consignment['tracking_code'] ?? null)) {
            throw ValidationException::withMessages([
                'steadfast' => $response['message'] ?? 'Steadfast order creation failed.',
            ]);
        }

        $status = $this->normalizeSteadfastStatus((string) ($consignment['status'] ?? 'in_review')) ?? CourierBooking::STATUS_BOOKING_PENDING;
        $booking = CourierBooking::query()->create([
            'company_id' => $order->company_id,
            'courier_provider_id' => $provider->getKey(),
            'order_id' => $order->getKey(),
            'tracking_id' => $consignment['tracking_code'],
            'provider_reference' => (string) ($consignment['consignment_id'] ?? ''),
            'recipient_name' => $consignment['recipient_name'] ?? $payload['recipient_name'],
            'recipient_phone' => $consignment['recipient_phone'] ?? $payload['recipient_phone'],
            'recipient_address' => $consignment['recipient_address'] ?? $payload['recipient_address'],
            'cod_amount' => $consignment['cod_amount'] ?? $payload['cod_amount'],
            'status' => $status,
            'booked_at' => now(),
            'note' => $payload['note'] ?? null,
        ]);

        $this->logStatus($booking, null, $status, $response['message'] ?? 'Steadfast consignment created.');
        $this->syncOrderDeliveryStatus($order, $status, $response['message'] ?? 'Steadfast consignment created.');
        $this->applyMargin($booking, $order, $provider);

        return $booking;
    }

    public function syncSteadfastStatus(CourierBooking $booking): CourierBooking
    {
        $booking->loadMissing('provider', 'order');

        if ($booking->provider?->driver !== CourierProvider::DRIVER_STEADFAST) {
            throw ValidationException::withMessages([
                'provider' => 'Only Steadfast bookings can be synced with Steadfast.',
            ]);
        }

        $response = $this->callCourier('Steadfast', fn (): array => filled($booking->tracking_id)
            ? app(SteadfastCourierClient::class)->statusByTrackingCode($booking->provider, $booking->tracking_id)
            : app(SteadfastCourierClient::class)->statusByInvoice($booking->provider, $booking->order->order_number));

        if (($response['status'] ?? null) !== 200 || blank($response['delivery_status'] ?? null)) {
            throw ValidationException::withMessages([
                'steadfast' => 'Unable to sync Steadfast delivery status.',
            ]);
        }

        $status = $this->normalizeSteadfastStatus((string) $response['delivery_status']);

        // "unknown" means Steadfast itself can't say — keep what we have.
        if ($status === null) {
            return $booking;
        }

        return $this->updateStatus(
            $booking,
            $status,
            'Synced from Steadfast: '.$response['delivery_status'],
        );
    }

    public function createPathaoBooking(Order $order, CourierProvider $provider, array $data = []): CourierBooking
    {
        $order->loadMissing(['customer', 'items.product', 'company']);
        app(CustomerRiskService::class)->assertNotBlacklisted($order);
        $this->assertProviderUsable($order, $provider, CourierProvider::DRIVER_PATHAO, 'Pathao');

        $payload = array_filter([
            'store_id' => (int) ($data['store_id'] ?? ($provider->settings['default_store_id'] ?? 0)),
            'merchant_order_id' => $order->order_number,
            'recipient_name' => $data['recipient_name'] ?? $order->customer_name ?? $order->customer?->name,
            'recipient_phone' => $data['recipient_phone'] ?? $order->customer?->phone,
            'recipient_address' => $data['recipient_address'] ?? $order->customer?->address,
            'recipient_city' => isset($data['recipient_city']) ? (int) $data['recipient_city'] : null,
            'recipient_zone' => isset($data['recipient_zone']) ? (int) $data['recipient_zone'] : null,
            'recipient_area' => isset($data['recipient_area']) ? (int) $data['recipient_area'] : null,
            'delivery_type' => (int) ($data['delivery_type'] ?? 48),
            'item_type' => (int) ($data['item_type'] ?? 2),
            'item_quantity' => (int) ($data['item_quantity'] ?? max(1, (int) $order->items->sum('quantity'))),
            'item_weight' => (float) ($data['item_weight'] ?? 0.5),
            'amount_to_collect' => (float) ($data['cod_amount'] ?? $order->courierCodAmount()),
            'item_description' => $data['item_description'] ?? $this->itemDescription($order),
            'special_instruction' => $this->courierNote($order, $data['note'] ?? null),
        ], fn ($value): bool => $value !== null && $value !== '');

        $response = $this->callCourier('Pathao', fn (): array => app(PathaoCourierClient::class)->createOrder($provider, $payload));
        $consignment = $response['data'] ?? [];

        if (blank($consignment['consignment_id'] ?? null)) {
            throw ValidationException::withMessages([
                'pathao' => $response['message'] ?? 'Pathao order creation failed.',
            ]);
        }

        $status = $this->normalizePathaoStatus((string) ($consignment['order_status'] ?? 'Pending'));

        return $this->storeBooking($order, $provider, [
            'tracking_id' => (string) $consignment['consignment_id'],
            'provider_reference' => (string) $consignment['consignment_id'],
            'recipient_name' => $payload['recipient_name'] ?? 'Customer',
            'recipient_phone' => $payload['recipient_phone'] ?? null,
            'recipient_address' => $payload['recipient_address'] ?? null,
            'cod_amount' => $payload['amount_to_collect'],
            'note' => $payload['special_instruction'] ?? null,
        ], $status, $response['message'] ?? 'Pathao consignment created.');
    }

    public function syncPathaoStatus(CourierBooking $booking): CourierBooking
    {
        $booking->loadMissing('provider', 'order');

        if ($booking->provider?->driver !== CourierProvider::DRIVER_PATHAO) {
            throw ValidationException::withMessages([
                'provider' => 'Only Pathao bookings can be synced with Pathao.',
            ]);
        }

        $response = $this->callCourier('Pathao', fn (): array => app(PathaoCourierClient::class)->orderInfo($booking->provider, (string) $booking->tracking_id));
        $orderStatus = $response['data']['order_status'] ?? null;

        if (blank($orderStatus)) {
            throw ValidationException::withMessages([
                'pathao' => 'Unable to sync Pathao delivery status.',
            ]);
        }

        return $this->updateStatus(
            $booking,
            $this->normalizePathaoStatus((string) $orderStatus),
            'Synced from Pathao: '.$orderStatus,
        );
    }

    public function normalizePathaoStatus(string $status): string
    {
        $status = str($status)->lower()->toString();

        return match (true) {
            str_contains($status, 'partial') => CourierBooking::STATUS_PARTIAL_DELIVERED,
            str_contains($status, 'delivered') => CourierBooking::STATUS_DELIVERED,
            str_contains($status, 'return') => CourierBooking::STATUS_RETURNED,
            str_contains($status, 'cancel') => CourierBooking::STATUS_CANCELLED,
            str_contains($status, 'picked') => CourierBooking::STATUS_PICKED_UP,
            str_contains($status, 'transit'), str_contains($status, 'hub') => CourierBooking::STATUS_IN_TRANSIT,
            str_contains($status, 'pending') && ! str_contains($status, 'pickup') => CourierBooking::STATUS_BOOKING_PENDING,
            default => CourierBooking::STATUS_BOOKED,
        };
    }

    public function createRedxBooking(Order $order, CourierProvider $provider, array $data = []): CourierBooking
    {
        $order->loadMissing(['customer', 'items.product', 'company']);
        app(CustomerRiskService::class)->assertNotBlacklisted($order);
        $this->assertProviderUsable($order, $provider, CourierProvider::DRIVER_REDX, 'RedX');

        $payload = array_filter([
            'customer_name' => $data['recipient_name'] ?? $order->customer_name ?? $order->customer?->name,
            'customer_phone' => $data['recipient_phone'] ?? $order->customer?->phone,
            'customer_address' => $data['recipient_address'] ?? $order->customer?->address,
            'delivery_area' => $data['delivery_area'] ?? null,
            'delivery_area_id' => isset($data['delivery_area_id']) ? (int) $data['delivery_area_id'] : null,
            'merchant_invoice_id' => $order->order_number,
            'cash_collection_amount' => (string) (float) ($data['cod_amount'] ?? $order->courierCodAmount()),
            'parcel_weight' => (int) ($data['parcel_weight'] ?? 500),
            'instruction' => $this->courierNote($order, $data['note'] ?? null),
            'value' => (string) (float) ($order->total_amount ?? 0),
        ], fn ($value): bool => $value !== null && $value !== '');

        $response = $this->callCourier('RedX', fn (): array => app(RedxCourierClient::class)->createParcel($provider, $payload));
        $trackingId = $response['tracking_id'] ?? null;

        if (blank($trackingId)) {
            throw ValidationException::withMessages([
                'redx' => $response['message'] ?? 'RedX parcel creation failed.',
            ]);
        }

        return $this->storeBooking($order, $provider, [
            'tracking_id' => (string) $trackingId,
            'provider_reference' => (string) $trackingId,
            'recipient_name' => $payload['customer_name'] ?? 'Customer',
            'recipient_phone' => $payload['customer_phone'] ?? null,
            'recipient_address' => $payload['customer_address'] ?? null,
            'cod_amount' => (float) $payload['cash_collection_amount'],
            'note' => $payload['instruction'] ?? null,
        ], CourierBooking::STATUS_BOOKED, 'RedX parcel created.');
    }

    public function syncRedxStatus(CourierBooking $booking): CourierBooking
    {
        $booking->loadMissing('provider', 'order');

        if ($booking->provider?->driver !== CourierProvider::DRIVER_REDX) {
            throw ValidationException::withMessages([
                'provider' => 'Only RedX bookings can be synced with RedX.',
            ]);
        }

        $response = $this->callCourier('RedX', fn (): array => app(RedxCourierClient::class)->parcelInfo($booking->provider, (string) $booking->tracking_id));
        $status = $response['parcel']['status'] ?? null;

        if (blank($status)) {
            throw ValidationException::withMessages([
                'redx' => 'Unable to sync RedX delivery status.',
            ]);
        }

        return $this->updateStatus(
            $booking,
            $this->normalizeRedxStatus((string) $status),
            'Synced from RedX: '.$status,
        );
    }

    public function normalizeRedxStatus(string $status): string
    {
        $status = str($status)->lower()->replace('_', '-')->toString();

        return match (true) {
            str_contains($status, 'partial') => CourierBooking::STATUS_PARTIAL_DELIVERED,
            str_contains($status, 'delivered') => CourierBooking::STATUS_DELIVERED,
            str_contains($status, 'return') => CourierBooking::STATUS_RETURNED,
            str_contains($status, 'cancel') => CourierBooking::STATUS_CANCELLED,
            str_contains($status, 'picked') => CourierBooking::STATUS_PICKED_UP,
            str_contains($status, 'sorting'), str_contains($status, 'transit'),
            str_contains($status, 'delivery-in-progress'), str_contains($status, 'hold') => CourierBooking::STATUS_IN_TRANSIT,
            str_contains($status, 'pickup-pending') => CourierBooking::STATUS_BOOKING_PENDING,
            default => CourierBooking::STATUS_BOOKED,
        };
    }

    public function createECourierBooking(Order $order, CourierProvider $provider, array $data = []): CourierBooking
    {
        $order->loadMissing(['customer', 'items.product', 'company']);
        app(CustomerRiskService::class)->assertNotBlacklisted($order);
        $this->assertProviderUsable($order, $provider, CourierProvider::DRIVER_ECOURIER, 'E-Courier');

        $payload = array_filter([
            'recipient_name' => $data['recipient_name'] ?? $order->customer_name ?? $order->customer?->name,
            'recipient_mobile' => $data['recipient_phone'] ?? $order->customer?->phone,
            'recipient_city' => $data['recipient_city'] ?? null,
            'recipient_thana' => $data['recipient_thana'] ?? null,
            'recipient_zip' => $data['recipient_zip'] ?? null,
            'recipient_area' => $data['recipient_area'] ?? null,
            'recipient_address' => $data['recipient_address'] ?? $order->customer?->address,
            'package_code' => $data['package_code'] ?? ($provider->settings['default_package_code'] ?? null),
            'product_price' => (string) (float) ($data['cod_amount'] ?? $order->courierCodAmount()),
            'payment_method' => $data['payment_method'] ?? 'COD',
            'number_of_item' => (int) ($data['item_quantity'] ?? max(1, (int) $order->items->sum('quantity'))),
            'product_id' => $order->order_number,
            'comments' => $this->courierNote($order, $data['note'] ?? $this->itemDescription($order)),
        ], fn ($value): bool => $value !== null && $value !== '');

        $response = $this->callCourier('E-Courier', fn (): array => app(ECourierClient::class)->placeOrder($provider, $payload));
        $trackingId = $response['ID'] ?? $response['id'] ?? null;

        if (($response['success'] ?? true) === false || blank($trackingId)) {
            throw ValidationException::withMessages([
                'ecourier' => $response['message'] ?? 'E-Courier order placement failed.',
            ]);
        }

        return $this->storeBooking($order, $provider, [
            'tracking_id' => (string) $trackingId,
            'provider_reference' => (string) $trackingId,
            'recipient_name' => $payload['recipient_name'] ?? 'Customer',
            'recipient_phone' => $payload['recipient_mobile'] ?? null,
            'recipient_address' => $payload['recipient_address'] ?? null,
            'cod_amount' => (float) $payload['product_price'],
            'note' => $this->courierNote($order, $data['note'] ?? null),
        ], CourierBooking::STATUS_BOOKED, $response['message'] ?? 'E-Courier order placed.');
    }

    public function syncECourierStatus(CourierBooking $booking): CourierBooking
    {
        $booking->loadMissing('provider', 'order');

        if ($booking->provider?->driver !== CourierProvider::DRIVER_ECOURIER) {
            throw ValidationException::withMessages([
                'provider' => 'Only E-Courier bookings can be synced with E-Courier.',
            ]);
        }

        $response = $this->callCourier('E-Courier', fn (): array => app(ECourierClient::class)->track($booking->provider, (string) $booking->tracking_id));
        $status = $response['status'] ?? data_get($response, 'query_data.status');

        if (is_array($status)) {
            $status = collect($status)->last()['status'] ?? null;
        }

        if (blank($status)) {
            throw ValidationException::withMessages([
                'ecourier' => 'Unable to sync E-Courier delivery status.',
            ]);
        }

        return $this->updateStatus(
            $booking,
            $this->normalizeECourierStatus((string) $status),
            'Synced from E-Courier: '.$status,
        );
    }

    public function normalizeECourierStatus(string $status): string
    {
        $status = str($status)->lower()->toString();

        return match (true) {
            str_contains($status, 'partial') => CourierBooking::STATUS_PARTIAL_DELIVERED,
            str_contains($status, 'delivered') => CourierBooking::STATUS_DELIVERED,
            str_contains($status, 'return') => CourierBooking::STATUS_RETURNED,
            str_contains($status, 'cancel') => CourierBooking::STATUS_CANCELLED,
            str_contains($status, 'picked') => CourierBooking::STATUS_PICKED_UP,
            str_contains($status, 'transit'), str_contains($status, 'shipped') => CourierBooking::STATUS_IN_TRANSIT,
            default => CourierBooking::STATUS_BOOKED,
        };
    }

    /**
     * The courier clients ->throw() on an HTTP error, so a booking the
     * courier refuses (bad phone, wrong keys, duplicate invoice, ...) used
     * to surface as a raw exception: no readable message on the Book
     * courier popup, and a bulk booking stopped at that order. Turn it into
     * a validation error carrying the courier's own reason. Status syncs go
     * through here too.
     *
     * @param  Closure(): mixed  $call
     */
    protected function callCourier(string $courier, Closure $call): array
    {
        try {
            return (array) $call();
        } catch (RequestException $exception) {
            $body = $exception->response->json();
            $reason = collect(is_array($body) ? [$body['message'] ?? null, $body['errors'] ?? null, $body['error'] ?? null] : [])
                ->flatten()
                ->filter(fn ($value): bool => is_string($value) && filled($value))
                ->unique()
                ->implode(' ');

            throw ValidationException::withMessages([
                'courier' => __(':courier refused the booking: :reason', [
                    'courier' => $courier,
                    'reason' => $reason !== '' ? $reason : 'HTTP '.$exception->response->status(),
                ]),
            ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'courier' => __('Could not reach :courier. Check the internet connection and try again.', ['courier' => $courier]),
            ]);
        }
    }

    protected function assertProviderUsable(Order $order, CourierProvider $provider, string $driver, string $label): void
    {
        if ($provider->driver !== $driver) {
            throw ValidationException::withMessages([
                'provider' => "Please select a {$label} courier provider.",
            ]);
        }

        if ((int) $provider->company_id !== (int) $order->company_id) {
            throw ValidationException::withMessages([
                'provider' => 'The courier provider must belong to the same company as the order.',
            ]);
        }
    }

    protected function storeBooking(Order $order, CourierProvider $provider, array $attributes, string $status, string $logNote): CourierBooking
    {
        $booking = CourierBooking::query()->create(array_merge([
            'company_id' => $order->company_id,
            'courier_provider_id' => $provider->getKey(),
            'order_id' => $order->getKey(),
            'status' => $status,
            'booked_at' => now(),
        ], $attributes));

        $this->logStatus($booking, null, $status, $logNote);
        $this->syncOrderDeliveryStatus($order, $status, $logNote);
        $this->applyMargin($booking, $order, $provider);

        return $booking;
    }

    /**
     * Records what we charge the customer for delivery versus what the
     * courier actually costs us, so bookings carry their own profit margin
     * instead of that only being visible inside the provider's settings.
     * Reads settings.delivery_fees / settings.delivery_costs (both keyed by
     * the same inside/outside/suburb zones as ShippingFeeService::ZONES)
     * plus settings.cod_charge_percent, all optional — bookings for
     * providers that haven't configured these simply keep null margin
     * fields rather than failing.
     */
    protected function applyMargin(CourierBooking $booking, Order $order, CourierProvider $provider): void
    {
        $zone = in_array($order->shipping_zone, ShippingFeeService::ZONES, true) ? $order->shipping_zone : 'outside';
        $settings = $provider->settings ?? [];

        $deliveryFee = data_get($settings, "delivery_fees.{$zone}");
        $deliveryCost = data_get($settings, "delivery_costs.{$zone}");
        $codPercent = data_get($settings, 'cod_charge_percent');

        if ($deliveryFee === null && $deliveryCost === null && $codPercent === null) {
            return;
        }

        $codAmount = (float) $booking->cod_amount;
        $codChargeAmount = $codPercent !== null ? round($codAmount * ((float) $codPercent / 100), 2) : null;
        $margin = $deliveryFee !== null
            ? round((float) $deliveryFee - (float) ($deliveryCost ?? 0) - (float) ($codChargeAmount ?? 0), 2)
            : null;

        $booking->forceFill([
            'delivery_fee_charged' => $deliveryFee !== null ? (float) $deliveryFee : null,
            'delivery_cost' => $deliveryCost !== null ? (float) $deliveryCost : null,
            'cod_charge_amount' => $codChargeAmount,
            'margin' => $margin,
        ])->save();
    }

    /**
     * The note every courier booking carries in the courier's own note
     * field (owner's rule): "Order placed from {company name}", followed by
     * any note staff typed. Never duplicated when staff kept the prefilled
     * line. Always English — it is read by the courier, not shown in the UI.
     */
    public function courierNote(Order $order, ?string $note = null): ?string
    {
        $order->loadMissing('company');
        $note = trim((string) $note);
        $companyName = trim((string) $order->company?->name);

        if ($companyName === '') {
            return $note === '' ? null : $note;
        }

        $line = "Order placed from {$companyName}";

        if ($note === '') {
            return $line;
        }

        return str_contains(mb_strtolower($note), mb_strtolower($line)) ? $note : "{$line} | {$note}";
    }

    protected function itemDescription(Order $order): string
    {
        return $order->items
            ->map(fn ($item): string => trim(($item->product?->name ?? 'Item').' x '.$item->quantity))
            ->filter()
            ->implode(', ');
    }

    /**
     * Requests a return/reverse-pickup for a Steadfast booking through the
     * live API and records the request locally so staff can browse/filter
     * return history without leaving the ERP. Returns the created record.
     */
    public function requestReturn(CourierBooking $booking, array $data = []): CourierReturn
    {
        $booking->loadMissing('provider', 'order');

        if ($booking->provider?->driver !== CourierProvider::DRIVER_STEADFAST) {
            throw ValidationException::withMessages([
                'provider' => 'Return requests are currently only supported for Steadfast bookings.',
            ]);
        }

        $response = app(SteadfastCourierClient::class)->createReturnRequest($booking->provider, array_filter([
            'consignment_id' => $booking->provider_reference,
            'tracking_code' => $booking->tracking_id,
            'reason' => $data['reason'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== ''));

        if (($response['status'] ?? null) !== 200) {
            throw ValidationException::withMessages([
                'steadfast' => $response['message'] ?? 'Steadfast return request failed.',
            ]);
        }

        $return = CourierReturn::query()->create([
            'company_id' => $booking->company_id,
            'courier_provider_id' => $booking->courier_provider_id,
            'courier_booking_id' => $booking->getKey(),
            'provider_reference' => (string) (data_get($response, 'return.id') ?? data_get($response, 'return_id') ?? ''),
            'reason' => $data['reason'] ?? null,
            'note' => $response['message'] ?? 'Return requested from ERP.',
        ]);

        $this->logStatus($booking, $booking->status, $booking->status, 'Return requested: '.($response['message'] ?? 'submitted to Steadfast.'));

        return $return;
    }

    public function updateStatus(CourierBooking $booking, string $status, ?string $note = null, bool $syncOrder = true): CourierBooking
    {
        if (! array_key_exists($status, CourierBooking::STATUSES)) {
            throw ValidationException::withMessages([
                'status' => 'Please select a valid courier status.',
            ]);
        }

        $fromStatus = $booking->status;

        $booking->forceFill([
            'status' => $status,
            'delivered_at' => $status === CourierBooking::STATUS_DELIVERED ? now() : $booking->delivered_at,
            'returned_at' => $status === CourierBooking::STATUS_RETURNED ? now() : $booking->returned_at,
            'note' => $note ?? $booking->note,
        ])->save();

        $this->logStatus($booking, $fromStatus, $status, $note);
        if ($syncOrder && $booking->order) {
            $this->syncOrderDeliveryStatus($booking->order, $status, $note);
        }

        if ($booking->order) {
            app(CustomerRiskService::class)->recordDeliveryEvent($booking->order, $status);
        }

        return $booking->refresh();
    }

    protected function syncOrderDeliveryStatus(Order $order, string $status, ?string $note = null): void
    {
        $stage = match ($status) {
            CourierBooking::STATUS_PICKED_UP,
            CourierBooking::STATUS_IN_TRANSIT,
            CourierBooking::STATUS_PARTIAL_DELIVERED => Order::STAGE_SHIPPING,
            CourierBooking::STATUS_DELIVERED => Order::STAGE_DELIVERED,
            CourierBooking::STATUS_RETURNED => Order::STAGE_RETURNED,
            default => null,
        };

        if ($stage && in_array($stage, $order->allowedWorkflowStages(), true)) {
            app(OrderStatusWorkflowService::class)->transition(
                $order,
                $stage,
                $stage === Order::STAGE_RETURNED ? ($note ?: 'Courier reported the order as returned.') : $note,
                'courier',
            );

            return;
        }

        if ($order->delivery_status === $status) {
            return;
        }

        $order->statusTransitionStage = $stage;
        $order->statusTransitionReason = $note;
        $order->statusTransitionSource = 'courier';
        $order->statusTransitionActorId = Auth::id();

        try {
            $order->forceFill(['delivery_status' => $status])->save();
        } finally {
            $order->clearStatusTransitionContext();
        }
    }

    protected function manualTrackingId(Order $order): string
    {
        return 'MAN-'.$order->getKey().'-'.now()->format('YmdHis');
    }

    protected function steadfastPayload(Order $order, array $data): array
    {
        $itemDescription = $data['item_description'] ?? $order->items
            ->map(fn ($item): string => trim(($item->product?->name ?? 'Item').' x '.$item->quantity))
            ->filter()
            ->implode(', ');

        return array_filter([
            'invoice' => $order->order_number,
            'recipient_name' => $data['recipient_name'] ?? $order->customer_name ?? $order->customer?->name,
            'recipient_phone' => $data['recipient_phone'] ?? $order->customer?->phone,
            'alternative_phone' => $data['alternative_phone'] ?? null,
            'recipient_email' => $data['recipient_email'] ?? $order->customer?->email,
            'recipient_address' => $data['recipient_address'] ?? $order->customer?->address,
            'cod_amount' => $data['cod_amount'] ?? $order->courierCodAmount(),
            'note' => $this->courierNote($order, $data['note'] ?? $order->note),
            'item_description' => $itemDescription,
            'total_lot' => $data['total_lot'] ?? $order->items->sum('quantity'),
            'delivery_type' => $data['delivery_type'] ?? 0,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Maps Steadfast's statuses (merchant API guide, September 2026) onto
     * ours. Anything ending `_approval_pending` is only the rider's word —
     * the guide says not to settle books on it — so it stays In Transit
     * until Steadfast confirms delivered / partial_delivered / cancelled.
     * The `*_return_*` statuses come from status_with_return_status_by_cid
     * for a parcel that didn't fully arrive. `unknown` ("not a state this
     * API recognises — ask us") returns null: keep the current status.
     */
    public function normalizeSteadfastStatus(string $status): ?string
    {
        return match (strtolower(trim($status))) {
            'delivered' => CourierBooking::STATUS_DELIVERED,
            'partial_delivered',
            'partial_delivered_return_processing',
            'partial_delivered_return_rider_assigned',
            'partial_delivered_return_received' => CourierBooking::STATUS_PARTIAL_DELIVERED,
            'cancelled',
            'cancelled_return_processing',
            'cancelled_return_rider_assigned' => CourierBooking::STATUS_CANCELLED,
            'cancelled_return_received' => CourierBooking::STATUS_RETURNED,
            'in_review' => CourierBooking::STATUS_BOOKING_PENDING,
            'pending', 'hold',
            'delivered_approval_pending',
            'partial_delivered_approval_pending',
            'cancelled_approval_pending',
            'unknown_approval_pending' => CourierBooking::STATUS_IN_TRANSIT,
            'exception' => CourierBooking::STATUS_FAILED,
            'unknown', '' => null,
            default => CourierBooking::STATUS_BOOKED,
        };
    }

    protected function logStatus(CourierBooking $booking, ?string $fromStatus, string $toStatus, ?string $note = null): void
    {
        CourierStatusLog::query()->create([
            'company_id' => $booking->company_id,
            'courier_booking_id' => $booking->getKey(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'created_by' => Auth::id(),
        ]);
    }
}
