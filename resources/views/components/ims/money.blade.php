{{--
    A money amount the IMS way (IMS-V2/DESIGN.md §4–5): lakh grouping, Bangla
    digits in Bangla, and the paisa part in a lighter colour ("৳ 32,678.90").
--}}
@props([
    'amount' => 0,
    'currency' => 'BDT',
    'signed' => false,
])

@php
    $text = \App\Support\ImsNumbers::money($amount, $currency, $signed);
    $dot = mb_strrpos($text, '.');
    [$major, $minor] = $dot === false ? [$text, ''] : [mb_substr($text, 0, $dot), mb_substr($text, $dot)];
@endphp

<span {{ $attributes->class(['ims-money tabular-nums']) }}>{{ $major }}@if ($minor !== '')<span class="ims-amount-minor">{{ $minor }}</span>@endif</span>
