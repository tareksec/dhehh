<?php

namespace App\Services\Crm;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LeadConversionService
{
    /**
     * Converts a Lead into a Customer, reusing an existing customer with the
     * same phone number inside the same company to avoid duplicates.
     */
    public function convertToCustomer(Lead $lead): Customer
    {
        if ($lead->converted_customer_id) {
            return $lead->convertedCustomer;
        }

        return DB::transaction(function () use ($lead) {
            $lead = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();
            if ($lead->converted_customer_id) {
                return $lead->convertedCustomer;
            }
            $existing = Customer::matchOrRestore(fn (Builder $query) => $query
                ->where('company_id', $lead->company_id)
                ->where('phone', $lead->phone));

            $customer = $existing ?? Customer::query()->create([
                'company_id' => $lead->company_id,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'customer_type' => 'regular',
                'customer_source' => $lead->source,
                'opening_balance' => 0,
                'is_active' => true,
            ]);

            $lead->update(['converted_customer_id' => $customer->getKey()]);

            return $customer;
        });
    }

    /**
     * Converts an accepted Quotation into a draft Order. Totals, stock and
     * balances are handled by the existing Order/OrderItem lifecycle.
     */
    public function convertQuotationToOrder(Quotation $quotation): Order
    {
        if ($quotation->converted_order_id) {
            return $quotation->convertedOrder;
        }

        if ($quotation->status !== 'accepted') {
            throw new \RuntimeException('Only accepted quotations can be converted to an order.');
        }

        return DB::transaction(function () use ($quotation) {
            $quotation = Quotation::query()->whereKey($quotation->getKey())->lockForUpdate()->firstOrFail();
            if ($quotation->converted_order_id) {
                return $quotation->convertedOrder;
            }
            if ($quotation->status !== 'accepted') {
                throw new \RuntimeException('Only accepted quotations can be converted to an order.');
            }
            $lead = $quotation->lead?->trashed() ? null : $quotation->lead;
            $customer = $quotation->customer
                ?? ($lead ? $this->convertToCustomer($lead) : null);

            if (! $customer && $quotation->lead?->trashed()) {
                throw new \RuntimeException(__('The lead of this quotation is in Trash. Restore the lead first, or choose a customer for the quotation.'));
            }

            if (! $customer) {
                throw new \RuntimeException('Quotation must have a customer or lead to convert.');
            }

            $order = Order::query()->create([
                'company_id' => $quotation->company_id,
                'customer_id' => $customer->getKey(),
                'customer_name' => $customer->name,
                'order_date' => now()->toDateString(),
                'discount' => $quotation->discount_amount,
                'vat' => 0,
                'paid_amount' => 0,
                'status' => 'draft',
                'source' => Order::SOURCE_CRM,
                'note' => "Created from CRM quotation {$quotation->quotation_number}",
            ]);

            foreach ($quotation->items()->with(['product', 'productVariant'])->get() as $item) {
                OrderItem::query()->create([
                    'company_id' => $quotation->company_id,
                    'order_id' => $order->getKey(),
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'variant_label' => $item->variant_label,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'unit_cost' => ($item->productVariant?->cost_price
                        ?? $item->product?->cost_price) ?? 0,
                ]);
            }

            $order->refresh();

            $quotation->update(['converted_order_id' => $order->getKey()]);

            if ($lead) {
                $lead->update([
                    'status' => 'won',
                    'converted_order_id' => $order->getKey(),
                ]);
            }

            return $order;
        });
    }
}
