<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @font-face {
            font-family: 'Hind Siliguri';
            font-weight: normal;
            src: url("{{ resource_path('fonts/ad-creative/HindSiliguri-Regular.ttf') }}") format('truetype');
        }
        @font-face {
            font-family: 'Hind Siliguri';
            font-weight: bold;
            src: url("{{ resource_path('fonts/ad-creative/HindSiliguri-Bold.ttf') }}") format('truetype');
        }
        body { color: #111418; font-family: 'Hind Siliguri', DejaVu Sans, sans-serif; font-size: 11px; }
        .header { border-bottom: 3px solid #16834f; margin-bottom: 16px; padding-bottom: 10px; }
        .brand { font-size: 20px; font-weight: bold; color: #0e6b3e; }
        .muted { color: #6b7280; }
        h2 { font-size: 14px; margin: 18px 0 6px; color: #0e6b3e; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #e6e8eb; padding: 6px 8px; text-align: left; }
        th { background: #dcefe4; }
        td.amount, th.amount { text-align: right; }
        .total td { font-weight: bold; }
        .note { margin-top: 14px; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">{{ $company?->name }}</div>
        <div class="muted">@yield('subtitle')</div>
    </div>

    @yield('content')

    <div class="note">{{ __('Made by IMS on :date.', ['date' => \App\Support\ImsNumbers::date(now())]) }}</div>
</body>
</html>
