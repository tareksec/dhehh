<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\StorefrontCheckoutPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Website -> ERP order creation, and a status-lookup endpoint the website
 * can poll as a fallback if a status webhook delivery was missed. Mirrors
 * Storefront\CheckoutController::createOrder() and
 * WooCommerceOrderSyncService's customer-by-phone + external_reference
 * idempotency conventions.
 */
class OrderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_reference' => ['required', 'string', 'max:190'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.address' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Prefixed the same way WooCommerce orders are ("woo-{id}"), so a
        // retried/duplicate POST from the website never creates a second
        // order for the same website order id.
        $externalReference = 'webapi-'.$data['external_reference'];

        $existing = Order::query()->where('external_reference', $externalReference)->first();

        if ($existing) {
            return response()->json(['data' => $this->transform($existing)]);
        }

        $order = DB::transaction(function () use ($data, $externalReference): Order {
            $policies = app(StorefrontCheckoutPolicyService::class);
            $phone = trim($data['customer']['phone']);

            $customer = Customer::query()
                ->whereIn('phone', $policies->phoneVariants($phone))
                ->first() ?? new Customer(['phone' => $phone]);

            $customer->fill([
                'name' => $data['customer']['name'],
                'email' => $data['customer']['email'] ?? $customer->email,
                'address' => $data['customer']['address'],
                'customer_type' => $customer->customer_type ?: 'regular',
                'customer_source' => 'website',
                'opening_balance' => $customer->opening_balance ?? 0,
                'is_active' => true,
            ]);
            $customer->save();

            $order = Order::query()->create([
                'customer_id' => $customer->getKey(),
                'customer_name' => $customer->name,
                'order_date' => now()->toDateString(),
                'status' => Order::STATUS_DRAFT,
                'source' => Order::SOURCE_API,
                'external_reference' => $externalReference,
                'note' => $data['note'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $product = Product::query()->where('sku', $item['sku'])->first();

                abort_if(! $product && Product::onlyTrashed()->where('sku', $item['sku'])->exists(), 422, "The product with SKU \"{$item['sku']}\" is in Trash.");
                abort_if(! $product, 422, "No product found with SKU \"{$item['sku']}\".");

                OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    'product_id' => $product->getKey(),
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'] ?? $product->selling_price,
                    'unit_cost' => $product->cost_price ?? 0,
                ]);
            }

            return $order->refresh();
        });

        return response()->json(['data' => $this->transform($order)], 201);
    }

    public function show(string $order_number): JsonResponse
    {
        $order = Order::query()->where('order_number', $order_number)->firstOrFail();

        return response()->json(['data' => $this->transform($order)]);
    }

    protected function transform(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'external_reference' => $order->external_reference,
            'status' => $order->status,
            'delivery_status' => $order->delivery_status,
            'workflow_stage' => $order->workflowStage(),
            'total_amount' => (float) $order->total_amount,
            'due_amount' => (float) $order->due_amount,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }
}
