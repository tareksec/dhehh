<?php

namespace App\Services;

use App\Models\CourierBooking;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\MoneyFormatter;
use App\Support\PhoneLinks;

/**
 * Builds the shareable "order summary card" shown from the order view page.
 * The card is drawn as a PNG image in the browser and sent as that image to
 * WhatsApp, WeChat, Messenger or Telegram; the plain text version is only
 * for the "Copy text" button.
 *
 * Like the printed invoice, a reseller's order carries the reseller's store
 * name and logo and none of the company's contact details (owner rule
 * 2026-10-07), and a reseller's own-courier customer only ever sees the
 * reseller's retail prices (owner rule 2026-10-06).
 */
class OrderSummaryCardService
{
    /** Side of the square product picture drawn on the card, in pixels. */
    protected const IMAGE_SIZE = 160;

    /** The logo is shrunk to fit inside this box (drawn at 2x on the card). */
    protected const LOGO_WIDTH = 520;

    protected const LOGO_HEIGHT = 160;

    /**
     * @return array{
     *     company: string,
     *     logo: ?string,
     *     contact: list<string>,
     *     address: string,
     *     invoice: string,
     *     title: string,
     *     order_rows: list<array{key: string, label: string, value: string}>,
     *     customer_rows: list<array{key: string, label: string, value: string}>,
     *     payment: array{key: string, label: string},
     *     items: list<array{name: string, quantity: int, price: string, amount: string, image: ?string}>,
     *     totals: list<array{key: string, label: string, value: string, strong: bool}>,
     *     note: ?string,
     *     labels: array<string, string>,
     *     text: string,
     *     tracking_url: ?string,
     *     customer_phone: ?string,
     *     file_name: string,
     * }
     */
    public function build(Order $order): array
    {
        $order->loadMissing(['company', 'reseller', 'customer', 'items.product', 'items.productVariant', 'latestCourierBooking.provider']);

        $currency = $order->company?->currency ?: 'BDT';
        $money = fn ($amount): string => MoneyFormatter::currency((float) $amount, $currency);

        // The card is sent to the buyer: a reseller's own-courier customer sees
        // only the reseller's retail prices and what they owe, never the
        // store's prices or the reseller's advance.
        $buyerView = $order->showsResellerPricesToBuyer();
        $resellerBrand = $order->showsResellerBranding();

        $courier = $buyerView ? $order->reseller_courier_name : $order->latestCourierBooking?->provider?->name;
        $tracking = $buyerView ? $order->reseller_parcel_id : $order->latestCourierBooking?->tracking_id;

        $orderRows = $this->filled([
            ['key' => 'invoice', 'label' => __('Invoice'), 'value' => (string) $order->order_number],
            ['key' => 'date', 'label' => __('Date'), 'value' => (string) $order->order_date?->format('d M Y')],
            ['key' => 'status', 'label' => __('Status'), 'value' => Order::STATUSES[$order->status] ?? (string) str($order->status)->headline()],
            ['key' => 'courier', 'label' => __('Courier'), 'value' => (string) $courier],
            ['key' => 'tracking', 'label' => __('Tracking ID'), 'value' => (string) $tracking],
        ]);

        $customerRows = $this->filled([
            ['key' => 'customer', 'label' => __('Customer'), 'value' => (string) ($order->customer_name ?: $order->customer?->name)],
            ['key' => 'phone', 'label' => __('Phone'), 'value' => (string) $order->customer?->phone],
            ['key' => 'address', 'label' => __('Address'), 'value' => (string) $order->customer?->address],
        ]);

        $items = $order->items
            ->map(fn (OrderItem $item): array => [
                'name' => trim(($item->product?->name ?? __('Item')).($item->variant_label ? ' ('.$item->variant_label.')' : '')),
                'quantity' => (int) $item->quantity,
                'image' => $this->itemImage($item, $order),
                'price' => $money($buyerView ? $item->buyerUnitPrice() : (float) $item->unit_price),
                'amount' => $money($buyerView
                    ? $item->buyerSubtotal()
                    : ((float) $item->subtotal ?: (int) $item->quantity * (float) $item->unit_price)),
            ])
            ->values()
            ->all();

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

        $total = $buyerView ? $order->buyerTotal() : (float) $order->total_amount;
        $due = $buyerView ? $order->buyerTotal() : (float) $order->due_amount;
        $paid = $buyerView ? 0.0 : (float) $order->paid_amount;
        $payment = match (true) {
            $total > 0 && $due <= 0 => ['key' => 'paid', 'label' => __('Paid in full')],
            $paid > 0 => ['key' => 'partial', 'label' => __('Partially paid')],
            default => ['key' => 'unpaid', 'label' => __('Payment due')],
        };

        $totals = array_map(fn (array $row): array => [
            'key' => $row['key'],
            'label' => $row['label'],
            'value' => ($row['minus'] ? '- ' : '').$money($row['value']),
            'strong' => $row['strong'],
        ], $totals);

        $company = $resellerBrand
            ? (string) $order->reseller?->resellerStoreName()
            : (string) ($order->company?->name ?? config('app.name'));
        $contact = $resellerBrand ? [] : $this->contactLines($order);
        $address = $resellerBrand ? '' : trim((string) $order->company?->address);
        $note = $resellerBrand || $buyerView ? null : (filled($order->note) ? trim((string) $order->note) : null);
        $title = __('Order summary');

        $text = $this->text($company, $title, [...$orderRows, ...$customerRows], $items, $totals);

        return [
            'company' => $company,
            'logo' => $this->logo($order, $resellerBrand),
            'contact' => $contact,
            'address' => $address,
            'invoice' => (string) $order->order_number,
            'title' => $title,
            'order_rows' => $orderRows,
            'customer_rows' => $customerRows,
            'payment' => $payment,
            'items' => $items,
            'totals' => $totals,
            'note' => $note,
            'labels' => [
                'invoice' => __('Invoice'),
                'payment' => __('Payment'),
                'address' => __('Address'),
                'order_details' => __('Order details'),
                'customer_details' => __('Customer details'),
                'item' => __('Item'),
                'price' => __('Price'),
                'quantity' => __('Qty'),
                'amount' => __('Amount'),
                'note' => __('Note'),
                'thanks' => __('Thank you for your order!'),
            ],
            'text' => $text,
            'tracking_url' => $this->trackingUrl($order, $buyerView),
            // Customer's number with the country code (8801…): the Android
            // app opens WhatsApp straight in this customer's chat with the
            // card image attached.
            'customer_phone' => PhoneLinks::internationalDigits($order->customer?->phone),
            'file_name' => 'order-'.str($order->order_number)->slug().'.png',
        ];
    }

    /**
     * The card for the PDF (owner request 2026-11-01). Same data, with two differences: the courier's tracking code
     * is not shown, and in the place where it sat there is a button ("Track your parcel") that opens the courier's
     * own tracking page. The page address is the Tracking URL Template of the courier provider (Courier Providers →
     * the provider → Tracking URL Template); with no template, or no booking, the row is simply left out.
     *
     * @return array<string, mixed> build() plus 'tracking_url'; the 'tracking' row is a 'track_button' row or gone
     */
    public function buildForPdf(Order $order): array
    {
        $card = $this->build($order);
        $url = $card['tracking_url'];
        $rows = [];

        foreach ($card['order_rows'] as $row) {
            if ($row['key'] !== 'tracking') {
                $rows[] = $row;

                continue;
            }

            if (filled($url)) {
                $rows[] = ['key' => 'track_button', 'label' => __('Track your parcel'), 'value' => (string) $url];
            }
        }

        $card['order_rows'] = $rows;
        $card['file_name'] = 'order-'.str($order->order_number)->slug().'.pdf';

        return $card;
    }

    /**
     * Where the customer can follow this parcel: the courier provider's Tracking URL Template with the booking's
     * tracking ID in it. Null when the order has no live courier booking or the provider has no template (a
     * reseller's own-courier order has no booking of ours, so it has none either).
     */
    protected function trackingUrl(Order $order, bool $buyerView): ?string
    {
        $booking = $order->latestCourierBooking;

        if ($buyerView || ! $booking?->provider || blank($booking->tracking_id)
            || in_array($booking->status, [CourierBooking::STATUS_CANCELLED, CourierBooking::STATUS_FAILED], true)) {
            return null;
        }

        try {
            $url = app(CourierManager::class)->adapter($booking->provider)->trackingUrl($booking);
        } catch (\Throwable) {
            return null;
        }

        return filled($url) && preg_match('#^https?://#i', (string) $url) ? (string) $url : null;
    }

    /**
     * Company phone, e-mail, website and WhatsApp / hotline from the invoice
     * settings, one short line each (the header and footer of the card).
     *
     * @return list<string>
     */
    protected function contactLines(Order $order): array
    {
        $company = $order->company;
        $invoice = $company ? app(CompanySettingsService::class)->invoice($company) : [];
        $website = preg_replace('#^https?://#', '', rtrim((string) ($invoice['website'] ?? ''), '/'));

        return array_values(array_unique(array_filter([
            trim((string) ($invoice['hotline'] ?? '')) ?: trim((string) $company?->phone),
            trim((string) $company?->email),
            trim((string) $website),
        ], fn ($line): bool => $line !== '')));
    }

    /**
     * Rows whose value is empty are left off the card.
     *
     * @param  list<array{key: string, label: string, value: string}>  $rows
     * @return list<array{key: string, label: string, value: string}>
     */
    protected function filled(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => trim((string) $row['value']) !== ''));
    }

    /**
     * The company logo (the reseller's own store logo on a reseller order),
     * shrunk to fit a small box and embedded as a PNG data URI so the browser
     * canvas stays untainted. Null when there is no logo or it cannot be read:
     * the card then writes the name in large type instead.
     */
    protected function logo(Order $order, bool $resellerBrand): ?string
    {
        $path = $resellerBrand
            ? ($order->reseller?->reseller_logo ?: $order->reseller?->reseller_logo_dark)
            : ($order->company?->logo ?: ((array) $order->company?->settings)['dark_logo'] ?? null);

        return $this->imageDataUri($path, $order, self::LOGO_WIDTH, self::LOGO_HEIGHT, square: false);
    }

    /**
     * The picture of the product the customer ordered (the variant's own
     * first photo when it has one, else the product's main image), shrunk to
     * a small square JPEG and returned as a data URI. It is embedded so the
     * browser can draw it on the card canvas without a cross-origin request,
     * which would block exporting the card as an image. Null when the product
     * has no picture or it cannot be read: the card then shows a plain box.
     */
    protected function itemImage(OrderItem $item, Order $order): ?string
    {
        $path = collect($item->productVariant?->images ?? [])->first(fn ($image): bool => is_string($image) && $image !== '')
            ?: $item->product?->image;

        return $this->imageDataUri($path, $order, self::IMAGE_SIZE, self::IMAGE_SIZE, square: true);
    }

    protected function imageDataUri(mixed $path, Order $order, int $width, int $height, bool $square): ?string
    {
        if (! is_string($path) || $path === '' || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $bytes = app(CompanyStorageService::class)->readPublic($path, $order->company);
            $source = $bytes ? @imagecreatefromstring($bytes) : false;

            if (! $source) {
                return null;
            }

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);

            if ($sourceWidth < 1 || $sourceHeight < 1) {
                return null;
            }

            if ($square) {
                // Product picture: centre-crop to a square on white, as a JPEG.
                $side = min($sourceWidth, $sourceHeight);
                $canvas = imagecreatetruecolor($width, $height);
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
                imagecopyresampled(
                    $canvas, $source, 0, 0,
                    intdiv($sourceWidth - $side, 2), intdiv($sourceHeight - $side, 2),
                    $width, $height, $side, $side,
                );

                ob_start();
                imagejpeg($canvas, null, 82);
                $bytes = (string) ob_get_clean();

                return $bytes === '' ? null : 'data:image/jpeg;base64,'.base64_encode($bytes);
            }

            // Logo: fit inside the box (never enlarged), keep transparency, PNG.
            $scale = min($width / $sourceWidth, $height / $sourceHeight, 1);
            $targetWidth = max(1, (int) round($sourceWidth * $scale));
            $targetHeight = max(1, (int) round($sourceHeight * $scale));
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

            ob_start();
            imagepng($canvas);
            $bytes = (string) ob_get_clean();

            return $bytes === '' ? null : 'data:image/png;base64,'.base64_encode($bytes);
        } catch (\Throwable) {
            return null;
        }
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
