{{-- A paper that cannot be printed yet (TradeLcDocumentController): what is still empty, in the portal's language. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body { margin: 0; padding: 24px 16px; font: 16px/1.5 "Hind Siliguri", Arial, sans-serif; color: #111827; background: #f3f5f8; }
        main { max-width: 560px; margin: 0 auto; padding: 20px; border-radius: 16px; background: #fff; border: 1px solid #e3e7ed; }
        h1 { margin: 0 0 8px; font-size: 20px; }
        li { margin: 4px 0; }
        a { color: #1d4ed8; font-weight: 600; }
    </style>
</head>
<body>
<main>
    <h1>{{ $title }}</h1>
    <p>{{ __('This paper cannot be printed yet. Fill these in the LC file first:') }}</p>
    <ul>
        @foreach ($missing as $field)
            <li>{{ __($field) }}</li>
        @endforeach
    </ul>
    <p><a href="{{ \App\Filament\Trade\Resources\LcFiles\LcFileResource::getUrl('edit', ['record' => $file], panel: 'trade') }}">{{ __('Edit details') }}</a></p>
</main>
</body>
</html>
