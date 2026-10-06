<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\MoneyFormatter;
use App\Support\PhoneLinks;

/**
 * Builds the shareable "order summary card" shown from the order view page.
 * The card is drawn as a PNG image in the browser and sent as that image to
 * WhatsApp, WeChat, Messenger or Telegram; the plain text version is only
 * for the "Copy text" button.
 */
class OrderSummaryCardService
{
    /**
     * @return array{
     *     company: string,
     *     company_phone: ?string,
     *     title: string,
     *     meta: list<array{label: string, value: string}>,
     *     items: list<array{name: string, quantity: int, amount: string}>,
     *     totals: list<array{key: string, label: string, value: string, strong: bool}>,
     *     labels: array<string, string>,
     *     text: string,
     *     customer_phone: ?string,
     *     file_name: string,
     * }
     */
    public function build(Order $order): array
    {
        $order->loadMissing(['company', 'reseller', 'customer', 'items.product', 'items.productVariant', 'latestCourierBooking.provider']);

        $currency = $order->company?->currency ?: 'BDT';
        $money = fn ($amount): string => MoneyFormatter::currency((float) $amount, $currency);

        $meta = array_values(array_filter([
            ['label' => __('Invoice'), 'value' => (string) $order->order_number],
            ['label' => __('Date'), 'value' => (string) $order->order_date?->format('d M Y')],
            ['label' => __('Customer'), 'value' => (string) ($order->customer_name ?: $order->customer?->name)],
            ['label' => __('Phone'), 'value' => (string) $order->customer?->phone],
            ['label' => __('Address'), 'value' => (string) $order->customer?->address],
            ['label' => __('Status'), 'value' => Order::STATUSES[$order->status] ?? (string) str($order->status)->headline()],
            ['label' => __('Courier'), 'value' => (string) $order->latestCourierBooking?->provider?->name],
            ['label' => __('Tracking ID'), 'value' => (string) $order->latestCourierBooking?->tracking_id],
        ], fn (array $row): bool => trim($row['value']) !== ''));

        $items = $order->items
            ->map(fn (OrderItem $item): array => [
                'name' => trim(($item->product?->name ?? __('Item')).($item->variant_label ? ' ('.$item->variant_label.')' : '')),
                'quantity' => (int) $item->quantity,
                'amount' => $money($order->showsResellerPricesToBuyer()
                    ? $item->buyerSubtotal()
                    : ((float) $item->subtotal ?: (int) $item->quantity * (float) $item->unit_price)),
            ])
            ->values()
            ->all();

        // The card is sent to the buyer: a reseller's own-courier customer sees
        // only the reseller's retail total and what they owe, never the
        // store's prices or the reseller's advance (owner rule).
        $buyerView = $order->showsResellerPricesToBuyer();
        $totals = $buyerView ? [
            ['key' => 'total', 'label' => __('Total'), 'value' => $order->buyerTotal(), 'strong' => true, 'always' => true, 'minus' => false],
            ['key' => 'due', 'label' => __('Due'), 'value' => $order->buyerTotal(), 'strong' => true, 'always' => true, 'minus' => false],
        ] : array_values(array_filter([
            ['key' => 'subtotal', 'label' => __('Subtotal'), 'value' => (float) $order->subtotal, 'strong' => false, 'always' => true, 'minus' => false],
            ['key' => 'delivery', 'label' => __('Delivery charge'), 'value' => (float) $order->shipping_fee, 'strong' => false, 'always' => false, 'minus' => false],
            ['key' => 'discount', 'label' => __('Discount'), 'value' => (float) $order->discount, 'strong' => false, 'always' => false, 'minus' => true],
            ['key' => 'vat', 'label' => __('VAT'), 'value' => (float) $order->vat, 'strong' => false, 'always' => false, 'minus' => false],
            ['key' => 'total', 'label' => __('Total'), 'value' => (float) $order->total_amount, 'strong' => true, 'always' => true, 'minus' => false],
            ['key' => 'paid', 'label' => __('Paid'), 'value' => (float) $order->paid_amount, 'strong' => false, 'always' => false, 'minus' => false],
            ['key' => 'due', 'label' => __('Due'), 'value' => (float) $order->due_amount, 'strong' => true, 'always' => true, 'minus' => false],
        ], fn (array $row): bool => $row['always'] || $row['value'] > 0));

        $totals = array_map(fn (array $row): array => [
            'key' => $row['key'],
            'label' => $row['label'],
            'value' => ($row['minus'] ? '- ' : '').$money($row['value']),
            'strong' => $row['strong'],
        ], $totals);

        // ...and it carries the reseller's store name, not the company's.
        $company = $buyerView
            ? (string) $order->reseller?->resellerStoreName()
            : (string) ($order->company?->name ?? config('app.name'));
        $title = __('Order summary');
        $text = $this->text($company, $title, $meta, $items, $totals);

        return [
            'company' => $company,
            'company_phone' => $buyerView ? null : $order->company?->phone,
            'title' => $title,
            'meta' => $meta,
            'items' => $items,
            'totals' => $totals,
            'labels' => [
                'items' => __('Items'),
                'quantity' => __('Qty'),
                'amount' => __('Amount'),
                'thanks' => __('Thank you for your order!'),
            ],
            'text' => $text,
            // Customer's number with the country code (8801…): the Android
            // app opens WhatsApp straight in this customer's chat with the
            // card image attached.
            'customer_phone' => PhoneLinks::internationalDigits($order->customer?->phone),
            'file_name' => 'order-'.str($order->order_number)->slug().'.png',
        ];
    }

    /**
     * @param  list<array{label: string, value: string}>  $meta
     * @param  list<array{name: string, quantity: int, amount: string}>  $items
     * @param  list<array{label: string, value: string, strong: bool}>  $totals
     */
    protected function text(string $company, string $title, array $meta, array $items, array $totals): string
    {
        $lines = ["*{$company}*", $title, ''];

        foreach ($meta as $row) {
            $lines[] = "{$row['label']}: {$row['value']}";
        }

        if ($items !== []) {
            $lines[] = '';
            $lines[] = '*'.__('Items').'*';

            foreach ($items as $index => $item) {
                $lines[] = ($index + 1).". {$item['name']} × {$item['quantity']} = {$item['amount']}";
            }
        }

        $lines[] = '';

        foreach ($totals as $row) {
            $lines[] = $row['strong']
                ? "*{$row['label']}: {$row['value']}*"
                : "{$row['label']}: {$row['value']}";
        }

        return implode("\n", $lines);
    }
}
