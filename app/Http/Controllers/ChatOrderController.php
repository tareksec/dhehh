<?php

namespace App\Http\Controllers;

use App\Models\ChatOrderLink;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Quotation;
use App\Services\CompanyContext;
use App\Services\Crm\ConversationMessengerService;
use App\Services\Crm\LeadConversionService;
use App\Services\Crm\SalesFollowUpService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ChatOrderController extends Controller
{
    public function show(string $token): View
    {
        $link = $this->linkFor($token);

        if (! $link->isUsable()) {
            return view('chat-order.closed', ['link' => $link]);
        }

        if (! $link->opened_at) {
            $link->forceFill(['opened_at' => now()])->saveQuietly();
        }

        if ($link->conversation) {
            app(SalesFollowUpService::class)->schedule($link->conversation, $link);
        }

        return view('chat-order.show', ['link' => $link]);
    }

    public function store(Request $request, string $token): View|RedirectResponse
    {
        $link = $this->linkFor($token);

        if (! $link->isUsable()) {
            return view('chat-order.closed', ['link' => $link]);
        }

        // Honeypot: bots fill every field; humans never see this one.
        if (filled($request->input('website'))) {
            abort(422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:1000'],
            'quantities' => ['array'],
            'quantities.*' => ['integer', 'min:1', 'max:1000'],
        ]);

        $context = app(CompanyContext::class);
        $context->set($link->company);

        try {
            $createdOrder = false;
            $order = DB::transaction(function () use ($link, $data, &$createdOrder) {
                $link = ChatOrderLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();
                if ($link->converted_order_id) {
                    return $link->convertedOrder;
                }
                abort_unless($link->isUsable(), 410, 'This order link has expired.');
                if ($link->quotation_id) {
                    $quotation = Quotation::query()->whereKey($link->quotation_id)->lockForUpdate()->firstOrFail();
                    if ($quotation->converted_order_id) {
                        $link->update(['converted_order_id' => $quotation->converted_order_id]);

                        return $quotation->convertedOrder;
                    }
                }
                $customer = $this->resolveCustomer($link, $data);

                $order = Order::query()->create([
                    'company_id' => $link->company_id,
                    'customer_id' => $customer->getKey(),
                    'customer_name' => $customer->name,
                    'order_date' => now()->toDateString(),
                    'discount' => 0,
                    'vat' => 0,
                    'paid_amount' => 0,
                    'status' => 'draft',
                    'source' => Order::SOURCE_CHAT,
                    'note' => 'Placed via chat order link.',
                ]);

                foreach ($link->prefill['items'] ?? [] as $index => $item) {
                    OrderItem::query()->create([
                        'company_id' => $link->company_id,
                        'order_id' => $order->getKey(),
                        'product_id' => $item['product_id'],
                        'product_variant_id' => $item['product_variant_id'] ?? null,
                        'variant_label' => $item['variant_label'] ?? null,
                        'quantity' => (int) ($data['quantities'][$index] ?? $item['quantity'] ?? 1),
                        'unit_price' => $item['unit_price'],
                    ]);
                }

                $order->refresh();
                $createdOrder = true;

                $link->forceFill(['converted_order_id' => $order->getKey()])->save();

                if ($link->quotation && ! $link->quotation->converted_order_id) {
                    $link->quotation->update(['status' => 'accepted', 'converted_order_id' => $order->getKey()]);
                }

                if ($link->lead) {
                    $link->lead->update([
                        'status' => 'won',
                        'converted_order_id' => $order->getKey(),
                        'converted_customer_id' => $link->lead->converted_customer_id ?? $customer->getKey(),
                    ]);
                }

                return $order;
            });

            if ($createdOrder) {
                $this->recordAndConfirm($link, $order);
            }
        } finally {
            $context->clear();
        }

        return view('chat-order.success', ['link' => $link, 'order' => $order]);
    }

    protected function linkFor(string $token): ChatOrderLink
    {
        return ChatOrderLink::withoutGlobalScopes()
            ->where('token', $token)
            ->with(['company', 'lead', 'quotation', 'conversation.channel'])
            ->firstOrFail();
    }

    protected function resolveCustomer(ChatOrderLink $link, array $data): Customer
    {
        if ($link->lead) {
            $customer = app(LeadConversionService::class)->convertToCustomer($link->lead);
        } else {
            $customer = Customer::matchOrRestore(fn (Builder $query) => $query
                ->where('company_id', $link->company_id)
                ->where('phone', $data['phone']))
                ?? Customer::query()->create([
                    'company_id' => $link->company_id,
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'customer_type' => 'regular',
                    'customer_source' => 'other',
                    'opening_balance' => 0,
                    'is_active' => true,
                ]);
        }

        if (blank($customer->address)) {
            $customer->update(['address' => $data['address']]);
        }

        return $customer;
    }

    protected function recordAndConfirm(ChatOrderLink $link, Order $order): void
    {
        $conversation = $link->conversation;

        if (! $conversation) {
            return;
        }

        try {
            $message = app(ConversationMessengerService::class)->send(
                $conversation,
                "আপনার অর্ডার কনফার্ম হয়েছে। অর্ডার নম্বর: {$order->order_number}। ধন্যবাদ!",
                null,
                'order_form',
            );

            if ($message->delivery_status === 'failed') {
                Log::warning('Chat order confirmation message failed.', [
                    'order_id' => $order->getKey(),
                    'error' => data_get($message->raw_payload, 'error.message'),
                ]);
            }
        } catch (\Throwable $exception) {
            // Never fail the customer's completed order because an unexpected
            // local archiving error occurred while preparing its confirmation.
            Log::warning('Chat order confirmation message failed.', [
                'order_id' => $order->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
