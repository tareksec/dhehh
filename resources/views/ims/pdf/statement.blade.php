@extends('ims.pdf.layout')

@php
    use App\Support\ImsNumbers;
    $currency = $company?->currency ?: 'BDT';
    $month = ImsNumbers::digits($statement['from']->copy()->locale(app()->getLocale())->translatedFormat('F Y'));
@endphp

@section('title', __('Statement').' '.$month)
@section('subtitle', __('Monthly statement').' — '.$account->name.' · '.$month)

@section('content')
    <h2>{{ __('Where you stand') }}</h2>
    <table>
        <tr><td>{{ __('Capital at work') }}</td><td class="amount">{{ ImsNumbers::money($statement['summary']['capital_at_work'], $currency) }}</td></tr>
        <tr><td>{{ __('Profit received') }}</td><td class="amount">{{ ImsNumbers::money($statement['summary']['profit_received'], $currency) }}</td></tr>
        <tr><td>{{ __('Profit confirmed, not yet paid') }}</td><td class="amount">{{ ImsNumbers::money($statement['summary']['profit_pending'], $currency) }}</td></tr>
    </table>

    <h2>{{ __('This month') }}</h2>
    @if ($statement['entries']->isEmpty())
        <p class="muted">{{ __('No money moved this month.') }}</p>
    @else
        <table>
            <thead>
                <tr><th>{{ __('Date') }}</th><th>{{ __('What') }}</th><th>{{ __('Project') }}</th><th class="amount">{{ __('Amount') }}</th></tr>
            </thead>
            <tbody>
                @foreach ($statement['entries'] as $entry)
                    <tr>
                        <td>{{ ImsNumbers::date($entry['date']) }}</td>
                        <td>{{ $entry['label'] }}</td>
                        <td>{{ $entry['project'] }}</td>
                        <td class="amount">{{ ImsNumbers::money($entry['amount'], $currency, true) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
