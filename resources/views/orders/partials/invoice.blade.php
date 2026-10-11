{{--
    Renders one order's invoice (+ courier slip). Shared by the single-order
    print view (orders.print) and the bulk print view (orders.print-bulk) —
    always include with explicit $order/$company/$invoice data so behaviour
    stays identical in both places:

        @include('orders.partials.invoice', ['order' => $order, 'company' => $company, 'invoice' => $invoice])

    Wrapped in .invoice-page so orders.print-bulk.blade.php's page-break
    rule (.invoice-page + .invoice-page) applies for free; harmless when
    there's only one on the page (the single-order view).
--}}
@php
    use App\Support\Code128;

    $company = $company ?? ['name' => config('app.name', 'Business Dashboard'), 'currency' => 'BDT'];
    $invoice = $invoice ?? \App\Services\CompanySettingsService::INVOICE_DEFAULTS;
    $currency = $company['currency'] ?? 'BDT';
    $money = fn (float $amount): string => \App\Support\MoneyFormatter::currency($amount, $currency);
    $discount = (float) $order->discount;
    $vat = (float) $order->vat;
    $shippingFee = (float) $order->shipping_fee;
    $paid = (float) $order->paid_amount;
    $due = (float) $order->due_amount;
    $subTotal = (float) $order->subtotal;
    $grandTotal = (float) $order->total_amount;
    $deliveryPartner = $order->latestCourierBooking?->provider?->name;
    $parcelId = $order->latestCourierBooking?->provider_reference;
    $brandName = $company['name'];
    $invoiceLogoUrl = $company['logo_url'] ?? $company['dark_logo_url'] ?? null;
    // A reseller's own-courier order goes to the reseller's customer, who must
    // only ever see the reseller's retail prices (owner rule) -- never the
    // store's price, the packaging charge, the advance or the internal note.
    // The invoice carries the reseller's store name and logo instead of the
    // company's, and none of the company's contact details; the customer
    // owes the whole retail total (owner rules, 2026-10-06).
    // Every reseller-portal order (also store-delivered, cash on delivery) is
    // invoiced in the reseller's name with none of the company's details or
    // the internal note (owner rule, 2026-10-07).
    $buyerView = $order->showsResellerPricesToBuyer();
    $resellerBrand = $order->showsResellerBranding();
    if ($buyerView) {
        $discount = $vat = $shippingFee = $paid = 0.0;
        $subTotal = $grandTotal = $due = $order->buyerTotal();
        $deliveryPartner = $order->reseller_courier_name;
        $parcelId = $order->reseller_parcel_id;
    }
    if ($resellerBrand) {
        $brandName = $order->reseller?->resellerStoreName() ?? '';
        $invoiceLogoUrl = $order->reseller?->resellerLogoUrl();
        $invoice = [...$invoice, 'hotline' => null, 'support_hotline' => null, 'facebook_url' => null, 'facebook_label' => null,
            'whatsapp' => null, 'website' => null, 'thank_you' => null];
        $company = [...$company, 'email' => null, 'address' => null];
    }
    $invoiceDate = optional($order->order_date)->format($company['date_format'] ?? 'd M Y');
    $customerName = $order->customer?->name ?? $order->customer_name;
    $customerPhone = $order->customer?->phone;
    $customerAddress = $order->customer?->address;
    $showImages = (bool) ($invoice['show_images'] ?? true);
    $showWeight = (bool) ($invoice['show_weight'] ?? true);
    $showBarcode = (bool) ($invoice['show_barcode'] ?? true);
    $barcodeSvg = $showBarcode ? Code128::svg($order->order_number) : '';
    $columnCount = 5 + ($showImages ? 1 : 0) + ($showWeight ? 1 : 0);
    $websiteLabel = preg_replace('#^https?://#', '', rtrim((string) ($invoice['website'] ?? ''), '/'));
@endphp
<div class="invoice-page">
    <main class="invoice">
        <header class="inv-header">
            <div class="logo">
                @if (filled($invoiceLogoUrl))
                    <img src="{{ $invoiceLogoUrl }}" alt="{{ $brandName }} logo" data-invoice-logo="main" decoding="sync" loading="eager">
                @endif
            </div>
            <div class="title">
                <h1>{{ $brandName }}</h1>
                @if (filled($invoice['hotline'] ?? null))
                    <div class="hotline">Hotline: {{ $invoice['hotline'] }}</div>
                @endif
            </div>
            <div></div>
        </header>

        <div class="inv-meta">
            <div class="bill-to">
                <p class="label">Bill To:</p>
                <p class="name">{{ $customerName }}</p>
                @if ($customerPhone)
                    <p class="phone">{{ $customerPhone }}</p>
                @endif
                @if ($customerAddress)
                    <p>{{ $customerAddress }}</p>
                @endif
            </div>
            <div class="inv-ref">
                @if ($barcodeSvg !== '')
                    <div class="barcode">{!! $barcodeSvg !!}</div>
                @endif
                <p>Invoice No: <strong>{{ $order->order_number }}</strong></p>
                @if ($deliveryPartner)
                    <p>Delivery Partner: <strong>{{ $deliveryPartner }}</strong></p>
                @endif
                @if ($parcelId)
                    <p>Parcel ID: <strong>{{ $parcelId }}</strong></p>
                @endif
                <p>Date: <strong>{{ $invoiceDate }}</strong></p>
            </div>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th class="center" style="width: 36px;">SL</th>
                    @if ($showImages)
                        <th style="width: 56px;">Image</th>
                    @endif
                    <th>Item Name</th>
                    @if ($showWeight)
                        <th class="center" style="width: 64px;">Weight</th>
                    @endif
                    <th class="num" style="width: 110px;">Unit Price ({{ $currency }})</th>
                    <th class="center" style="width: 60px;">Qty</th>
                    <th class="num" style="width: 130px;">Amount ({{ $currency }})</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td class="center">{{ $loop->iteration }}</td>
                        @if ($showImages)
                            <td class="item-image">
                                @if ($item->product?->image)
                                    <img src="{{ \App\Support\CompanyMedia::publicUrl($item->product->image, $order->company) }}" alt="{{ $item->product->name }}">
                                @endif
                            </td>
                        @endif
                        <td>
                            <span class="item-name">{{ $item->product?->name ?? 'Product' }}</span>
                            @if ($item->variant_label)
                                <div class="item-variant">{{ $item->variant_label }}</div>
                            @endif
                        </td>
                        @if ($showWeight)
                            <td class="center">
                                {{ $item->unitWeightKg() ? rtrim(rtrim(number_format($item->unitWeightKg(), 3), '0'), '.').' kg' : '—' }}
                            </td>
                        @endif
                        <td class="num">{{ $money($buyerView ? $item->buyerUnitPrice() : (float) $item->unit_price) }}</td>
                        <td class="center">{{ $item->quantity }}</td>
                        <td class="num">{{ $money($buyerView ? $item->buyerSubtotal() : (float) $item->subtotal) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-wrap">
            <div class="contact-block">
                @if (filled($invoice['facebook_url'] ?? null) || filled($invoice['facebook_label'] ?? null))
                    <p><span class="contact-icon">f</span>{{ filled($invoice['facebook_label'] ?? null) ? $invoice['facebook_label'] : preg_replace('#^https?://#', '', $invoice['facebook_url']) }}</p>
                @endif
                @if (! empty($company['email']))
                    <p><span class="contact-icon">&#9993;</span>{{ $company['email'] }}</p>
                @endif
                @if ($websiteLabel !== '')
                    <p><span class="contact-icon">&#127760;</span>{{ $websiteLabel }}</p>
                @endif
                @if (! empty($company['address']))
                    <p><span class="contact-icon">&#9906;</span>{{ $company['address'] }}</p>
                @endif
                @if ($order->note && ! $resellerBrand)
                    <p><strong>Note:</strong> {{ $order->note }}</p>
                @endif
            </div>
            <div>
                <table class="totals">
                    <tr class="row">
                        <td class="t-label">Sub Total</td>
                        <td class="num">{{ $money($subTotal) }}</td>
                    </tr>
                    @if ($discount > 0)
                        <tr class="row">
                            <td class="t-label">Discount</td>
                            <td class="num">{{ $money(-$discount) }}</td>
                        </tr>
                    @endif
                    @if ($vat > 0)
                        <tr class="row">
                            <td class="t-label">VAT</td>
                            <td class="num">{{ $money($vat) }}</td>
                        </tr>
                    @endif
                    @if ($shippingFee > 0)
                        <tr class="row">
                            <td class="t-label">Delivery Charge</td>
                            <td class="num">{{ $money($shippingFee) }}</td>
                        </tr>
                    @endif
                    <tr class="row">
                        <td class="t-label">Grand Total</td>
                        <td class="num">{{ $money($grandTotal) }}</td>
                    </tr>
                    @if ($paid > 0)
                        <tr class="row">
                            <td class="t-label">Paid</td>
                            <td class="num">{{ $money(-$paid) }}</td>
                        </tr>
                    @endif
                    <tr class="due">
                        <td class="t-label">Due Amount</td>
                        <td class="num">{{ $money($due) }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="invoice-footer">
            @php
                $stripParts = array_filter([
                    filled($invoice['support_hotline'] ?? null) ? ['Hotline:', $invoice['support_hotline']] : null,
                    filled($invoice['facebook_label'] ?? null) ? ['Facebook Page:', $invoice['facebook_label']] : null,
                    filled($invoice['whatsapp'] ?? null) ? ['WhatsApp:', $invoice['whatsapp']] : null,
                ]);
            @endphp
            @if ($stripParts !== [])
                <div class="contact-strip">
                    @foreach ($stripParts as $part)
                        <span><strong>{{ $part[0] }}</strong> {{ $part[1] }}</span>
                    @endforeach
                </div>
            @endif

            @if (filled($invoice['thank_you'] ?? null))
                <div class="thank-you">{{ $invoice['thank_you'] }}</div>
            @endif

            @if (! empty($invoice['show_slip']))
                <div class="cut-line-wrap">
                    <hr class="cut-line">
                    <span class="scissors">&#9986;</span>
                </div>

                <section class="slip" id="courier-slip">
                    <div class="slip-header">
                        <div class="logo">
                            @if (filled($invoiceLogoUrl))
                                <img src="{{ $invoiceLogoUrl }}" alt="{{ $brandName }} logo" data-invoice-logo="slip" decoding="sync" loading="eager">
                            @endif
                        </div>
                        <div class="title">
                            <h2>{{ $brandName }}</h2>
                            @if (filled($invoice['hotline'] ?? null))
                                <div class="hotline">Hotline: {{ $invoice['hotline'] }}</div>
                            @endif
                        </div>
                        <div></div>
                    </div>
                    <div class="slip-body">
                        <div class="bill-to">
                            <p class="label">Bill To:</p>
                            <p class="name">{{ $customerName }}</p>
                            @if ($customerPhone)
                                <p class="phone">{{ $customerPhone }}</p>
                            @endif
                            @if ($customerAddress)
                                <p>{{ $customerAddress }}</p>
                            @endif
                        </div>
                        <div class="slip-ref">
                            @if ($barcodeSvg !== '')
                                <div class="barcode">{!! $barcodeSvg !!}</div>
                            @endif
                            <p>Invoice No: <strong>{{ $order->order_number }}</strong></p>
                            @if ($deliveryPartner)
                                <p>Delivery Partner: <strong>{{ $deliveryPartner }}</strong></p>
                            @endif
                            @if ($parcelId)
                                <p>Parcel ID: <strong>{{ $parcelId }}</strong></p>
                            @endif
                            <p>Date: <strong>{{ $invoiceDate }}</strong></p>
                            <div class="slip-due">Due Amount: {{ $money($due) }}</div>
                        </div>
                    </div>
                </section>
            @endif
        </div>
    </main>
</div>
