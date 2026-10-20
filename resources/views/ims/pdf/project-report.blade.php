@extends('ims.pdf.layout')

@php
    use App\Support\ImsNumbers;
    $currency = $company?->currency ?: 'BDT';
    $figures = $summary['figures'] ?? [];
    $settled = $summary['settled'] ?? null;
@endphp

@section('title', $report->title)
@section('subtitle', __('Project report').' — '.($summary['project']['name'] ?? '').' · '.__('Published on :date', ['date' => ImsNumbers::date($report->published_at)]))

@section('content')
    <h2>{{ $report->title }}</h2>
    @if ($report->notes)
        <p>{{ $report->notes }}</p>
    @endif

    <h2>{{ __('Costs') }}</h2>
    <table>
        <tr><td>{{ __('Goods bought') }}</td><td class="amount">{{ ImsNumbers::money($figures['costs']['goods'] ?? 0, $currency) }}</td></tr>
        <tr><td>{{ __('Shipping, customs and import') }}</td><td class="amount">{{ ImsNumbers::money($figures['costs']['import'] ?? 0, $currency) }}</td></tr>
        <tr><td>{{ __('Other landed costs') }}</td><td class="amount">{{ ImsNumbers::money($figures['costs']['landed_items'] ?? 0, $currency) }}</td></tr>
        <tr><td>{{ __('Local expenses') }}</td><td class="amount">{{ ImsNumbers::money($figures['costs']['local_items'] ?? 0, $currency) }}</td></tr>
        <tr class="total"><td>{{ __('Total cost') }}</td><td class="amount">{{ ImsNumbers::money($figures['costs']['total'] ?? 0, $currency) }}</td></tr>
    </table>

    <h2>{{ __('Stock and sales') }}</h2>
    <table>
        <tr><td>{{ __('Units bought') }}</td><td class="amount">{{ ImsNumbers::count($figures['units_bought'] ?? 0) }}</td></tr>
        <tr><td>{{ __('Units sold') }}</td><td class="amount">{{ ImsNumbers::count($figures['units_sold'] ?? 0) }}</td></tr>
        <tr><td>{{ __('Units in stock') }}</td><td class="amount">{{ ImsNumbers::count($figures['units_in_stock'] ?? 0) }}</td></tr>
        <tr><td>{{ __('Sales') }}</td><td class="amount">{{ ImsNumbers::money($figures['revenue'] ?? 0, $currency) }}</td></tr>
    </table>

    <h2>{{ __('Profit') }}</h2>
    <table>
        @if ($settled)
            <tr><td>{{ __('Net profit (settled)') }}</td><td class="amount">{{ ImsNumbers::money($settled['net_profit'], $currency, true) }}</td></tr>
            <tr><td>{{ __('Investors\' share') }}</td><td class="amount">{{ ImsNumbers::money($settled['investor_pool'], $currency, true) }}</td></tr>
        @else
            <tr><td>{{ __('Net profit (estimate, not settled yet)') }}</td><td class="amount">{{ ImsNumbers::money($figures['net_profit'] ?? 0, $currency, true) }}</td></tr>
        @endif
        <tr><td>{{ __('Investors\' % of profit or loss') }}</td><td class="amount">{{ ImsNumbers::percent($summary['investor_share_percent'] ?? 0) }}</td></tr>
    </table>
@endsection
