{{-- One Delivery Challan in the Trade Portal: the paper's details, its lines, and the money (ViewDeliveryChallan). --}}
@php
    use App\Support\ImsNumbers;

    $challan = $this->getRecord()->loadMissing(['items', 'order.payments.account', 'serials']);
    $payments = $challan->order?->payments?->sortBy('paid_at') ?? collect();
    // Serial numbers show once, under the first line of their item.
    $serialsByProduct = $challan->serials->groupBy('product_id');
    $shown = [];
@endphp

<x-filament-panels::page>
    @if ($challan->isCancelled())
        <p class="trade-card-error">{{ __('Cancelled on :date: :reason', ['date' => ImsNumbers::date($challan->cancelled_at), 'reason' => $challan->cancel_reason]) }}</p>
    @endif

    <section class="trade-card">
        <dl class="trade-facts">
            <div><dt>{{ __('Buyer / Party name') }}</dt><dd>{{ $challan->buyer_name }}</dd></div>
            <div><dt>{{ __('Date') }}</dt><dd>{{ ImsNumbers::date($challan->challan_date) }}</dd></div>
            @if ($challan->buyer_phone)<div><dt>{{ __('Phone') }}</dt><dd>{{ $challan->buyer_phone }}</dd></div>@endif
            @if ($challan->buyer_address)<div><dt>{{ __('Address') }}</dt><dd>{{ $challan->buyer_address }}</dd></div>@endif
        </dl>
    </section>

    <section class="trade-card" aria-labelledby="trade-challan-goods">
        <h2 id="trade-challan-goods" class="trade-card-title">{{ __('Goods') }}</h2>
        <ul class="trade-list">
            @foreach ($challan->items as $item)
                <li>
                    <span>
                        {{ $item->description }}
                        @if ($item->remarks)<small>{{ $item->remarks }}</small>@endif
                        @if ($item->unit_price !== null)<small>{{ ImsNumbers::money($item->unit_price) }} × {{ ImsNumbers::digits((string) $item->quantity) }} = {{ ImsNumbers::money($item->lineTotal()) }}</small>@endif
                        @if ($item->product_id && isset($serialsByProduct[$item->product_id]) && ! isset($shown[$item->product_id]))
                            @php($shown[$item->product_id] = true)
                            <small>{{ __('S/N: :numbers', ['numbers' => $serialsByProduct[$item->product_id]->pluck('serial_number')->implode(', ')]) }}</small>
                        @endif
                    </span>
                    <strong>{{ ImsNumbers::digits((string) $item->quantity) }} {{ $item->unit }}</strong>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="trade-card" aria-labelledby="trade-challan-money">
        <h2 id="trade-challan-money" class="trade-card-title">{{ __('Money') }}</h2>
        <ul class="trade-list">
            <li><span>{{ __('Total price') }}</span><strong>{{ ImsNumbers::money($challan->total) }}</strong></li>
            @foreach ($payments as $payment)
                <li>
                    <span>
                        {{ $payment->type === \App\Models\OrderPayment::TYPE_ADVANCE ? __('Advance') : __('Received') }} · {{ ImsNumbers::date($payment->paid_at) }}
                        <small>{{ collect([$payment->account?->name, $payment->note])->filter()->implode(' · ') }}</small>
                    </span>
                    <strong>{{ ImsNumbers::money($payment->amount) }}</strong>
                </li>
            @endforeach
            <li><span><strong>{{ __('Due now') }}</strong></span><strong class="trade-due">{{ ImsNumbers::money($challan->dueNow()) }}</strong></li>
        </ul>
        @if ($challan->cheque_number || $challan->due_terms)
            <dl class="trade-facts">
                @if ($challan->cheque_number)<div><dt>{{ __('Cheque no.') }}</dt><dd>{{ $challan->cheque_number }} {{ $challan->cheque_bank }}</dd></div>@endif
                @if ($challan->due_terms)<div><dt>{{ __('When the due will be paid') }}</dt><dd>{{ $challan->due_terms }}</dd></div>@endif
            </dl>
        @endif
    </section>
</x-filament-panels::page>
