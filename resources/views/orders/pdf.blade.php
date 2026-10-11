<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $order->order_number }}</title>
    <style>
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        .top { border-bottom: 3px solid #f59e0b; display: table; margin-bottom: 24px; padding-bottom: 14px; width: 100%; }
        .brand, .invoice { display: table-cell; vertical-align: top; width: 50%; }
        .brand h1 { font-size: 25px; margin: 0 0 6px; }
        .invoice { text-align: right; }
        .invoice h2 { font-size: 22px; margin: 0 0 8px; }
        .muted { color: #6b7280; }
        .panel { background: #f9fafb; border: 1px solid #e5e7eb; margin-bottom: 18px; padding: 12px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #111827; color: #fff; font-size: 10px; text-align: left; text-transform: uppercase; }
        th, td { border: 1px solid #d1d5db; padding: 8px; }
        .right { text-align: right; }
        .totals { margin-left: auto; margin-top: 16px; width: 280px; }
        .totals td { border-color: #e5e7eb; }
        .grand td { background: #111827; color: #fff; font-weight: 700; }
        .product-image { height: 34px; object-fit: contain; width: 34px; }
        .barcode { margin-top: 10px; text-align: right; }
        .footer { border-top: 1px solid #d1d5db; margin-top: 28px; padding-top: 12px; text-align: center; }
        .cut-slip { page-break-before: always; }
        .cut-slip-brand { margin-bottom: 18px; }
        .cut-slip-brand img { display: block; margin-bottom: 6px; max-height: 26.2pt; max-width: 48pt; object-fit: contain; }
    </style>
</head>
<body>
    @php
        $company = $company ?? ['name' => config('app.name', 'Business Dashboard'), 'currency' => 'BDT'];
        $invoice = $invoice ?? \App\Services\CompanySettingsService::INVOICE_DEFAULTS;
        $showImages = (bool) ($invoice['show_images'] ?? true);
        $showWeight = (bool) ($invoice['show_weight'] ?? true);
        $invoiceLogoPath = $company['logo_path'] ?? $company['dark_logo_path'] ?? null;
        $currency = $company['currency'] ?? 'BDT';
        $subTotal = (float) $order->subtotal;
        $discount = (float) $order->discount;
        $vat = (float) $order->vat;
        $grandTotal = (float) $order->total_amount;
        $paid = (float) $order->paid_amount;
        $due = (float) $order->due_amount;
        // Same buyer view as orders.partials.invoice: a reseller's own-courier
        // customer sees only the reseller's store, logo and retail prices.
        // Every reseller-portal order carries the reseller's name (owner rule, 2026-10-07).
        $buyerView = $order->showsResellerPricesToBuyer();
        if ($buyerView) {
            $subTotal = $grandTotal = $due = $order->buyerTotal();
            $discount = $vat = $paid = 0.0;
        }
        if ($order->showsResellerBranding()) {
            $invoiceLogoPath = $resellerLogoPath ?? null;
            $company = [...$company, 'name' => $order->reseller?->resellerStoreName() ?? '', 'address' => null, 'phone' => null, 'email' => null];
            $invoice = [...$invoice, 'hotline' => null, 'support_hotline' => null, 'facebook_label' => null,
                'whatsapp' => null, 'website' => null, 'thank_you' => null];
        }
    @endphp
    <div class="top">
        <div class="brand">
            @if (filled($invoiceLogoPath))
                <img src="{{ $invoiceLogoPath }}" alt="{{ $company['name'] }} logo" data-invoice-logo="main" style="max-height: 48.7pt; max-width: 90pt; margin-bottom: 8px; object-fit: contain;">
            @endif
            <h1>{{ $company['name'] }}</h1>
            <div class="muted">
                @if (! empty($company['address'])){{ $company['address'] }}<br>@endif
                @if (! empty($company['phone'])){{ $company['phone'] }}@endif
                @if (! empty($company['email'])) | {{ $company['email'] }}@endif
            </div>
        </div>
        <div class="invoice">
            <h2>Invoice</h2>
            <div><strong>{{ $order->order_number }}</strong></div>
            <div class="muted">{{ optional($order->order_date)->format($company['date_format'] ?? 'd M Y') }}</div>
            @if (! empty($invoice['hotline']))
                <div>Hotline: {{ $invoice['hotline'] }}</div>
            @endif
            @if (! empty($invoice['show_barcode']))
                <div class="barcode">{!! \App\Support\Code128::svg($order->order_number, 34, 1) !!}</div>
            @endif
        </div>
    </div>

    <div class="panel">
        <strong>Bill To:</strong><br>
        {{ $order->customer?->name ?? $order->customer_name }}<br>
        <span class="muted">{{ $order->customer?->phone }}</span><br>
        <span class="muted">{{ $order->customer?->email }}</span>
    </div>

    <table>
        <thead>
            <tr>
                @if ($showImages)<th>Image</th>@endif
                <th>Product</th>
                @if ($showWeight)<th class="right">Weight</th>@endif
                <th class="right">Qty</th>
                <th class="right">Unit Price</th>
                <th class="right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    @if ($showImages)
                        <td>
                            @if (! empty($productImages[$item->getKey()] ?? null))
                                <img class="product-image" src="{{ $productImages[$item->getKey()] }}" alt="">
                            @endif
                        </td>
                    @endif
                    <td>{{ $item->product?->name }}</td>
                    @if ($showWeight)
                        <td class="right">{{ $item->unitWeightKg() ? rtrim(rtrim(number_format($item->unitWeightKg(), 3), '0'), '.').' kg' : '—' }}</td>
                    @endif
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ $currency }} {{ \App\Support\MoneyFormatter::number($buyerView ? $item->buyerUnitPrice() : (float) $item->unit_price) }}</td>
                    <td class="right">{{ $currency }} {{ \App\Support\MoneyFormatter::number($buyerView ? $item->buyerSubtotal() : (float) $item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="right">{{ $currency }} {{ \App\Support\MoneyFormatter::number($subTotal) }}</td></tr>
        @unless ($buyerView)
            <tr><td>Discount</td><td class="right">{{ $currency }} {{ \App\Support\MoneyFormatter::number($discount) }}</td></tr>
            <tr><td>VAT</td><td class="right">{{ $currency }} {{ \App\Support\MoneyFormatter::number($vat) }}</td></tr>
        @endunless
        <tr class="grand"><td>Total</td><td class="right">{{ $currency }} {{ \App\Support\MoneyFormatter::number($grandTotal) }}</td></tr>
        @unless ($buyerView)
            <tr><td>Paid</td><td class="right">{{ $currency }} {{ \App\Support\MoneyFormatter::number($paid) }}</td></tr>
        @endunless
        <tr><td>Due</td><td class="right">{{ $currency }} {{ \App\Support\MoneyFormatter::number($due) }}</td></tr>
    </table>

    <div class="footer">
        @if (! empty($invoice['thank_you']))<strong>{{ $invoice['thank_you'] }}</strong><br>@endif
        @if (! empty($invoice['support_hotline']))Hotline: {{ $invoice['support_hotline'] }}@endif
        @if (! empty($invoice['whatsapp'])) &nbsp; WhatsApp: {{ $invoice['whatsapp'] }}@endif
        @if (! empty($invoice['facebook_label'])) &nbsp; Facebook: {{ $invoice['facebook_label'] }}@endif
        @if (! empty($invoice['website'])) &nbsp; {{ $invoice['website'] }}@endif
    </div>

    @if (! empty($invoice['show_slip']))
        <div class="cut-slip">
            <div class="cut-slip-brand">
                @if (filled($invoiceLogoPath))
                    <img src="{{ $invoiceLogoPath }}" alt="{{ $company['name'] }} logo" data-invoice-logo="slip">
                @endif
                <strong>{{ $company['name'] }}</strong>
            </div>
            <h2>Courier Cut-Slip</h2>
            <p>Invoice: {{ $order->order_number }}</p>
            <p>Customer: {{ $order->customer?->name ?? $order->customer_name }}</p>
            <p>Phone: {{ $order->customer?->phone }}</p>
            <p>Address: {{ $order->customer?->address }}</p>
            <p>COD: {{ $currency }} {{ \App\Support\MoneyFormatter::number($due) }}</p>
            @if ($buyerView && $order->reseller_parcel_id)
                <p>Parcel ID: {{ $order->reseller_parcel_id }}{{ $order->reseller_courier_name ? ' ('.$order->reseller_courier_name.')' : '' }}</p>
            @endif
            @if (! empty($invoice['show_barcode']))
                <div>{!! \App\Support\Code128::svg($order->order_number, 40, 1) !!}</div>
            @endif
        </div>
    @endif
</body>
</html>
