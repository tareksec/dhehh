{{--
    Deliberately brand-neutral and dependency-free: no company/storefront
    theme data, no ZamZam ERP branding, no admin links. This renders when an
    unhandled exception hits a storefront request in production (see
    bootstrap/app.php's withExceptions()) — sometimes precisely because
    company/domain resolution itself failed, so it must never assume any of
    that context is available. See 09_DASHBOARD_WOOCOMMERCE_SECURITY_PLAN.md
    step 3.2.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? __('Unavailable') }}</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #111827;
            background: #f8fafc;
        }
        .card {
            width: 100%;
            max-width: 420px;
            padding: 40px 32px;
            text-align: center;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 20px 50px rgb(15 23 42 / .06);
        }
        .code { color: #94a3b8; font-size: 13px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 10px 0 12px; font-size: 24px; line-height: 1.3; }
        p { margin: 0 0 24px; color: #475569; font-size: 15px; line-height: 1.6; }
        a.home {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 20px;
            color: #ffffff;
            background: #111827;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="code">{{ $status ?? '' }}</div>
        <h1>{{ $heading }}</h1>
        <p>{{ $message }}</p>
        <a class="home" href="{{ url('/') }}">{{ __('Go to homepage') }}</a>
    </main>
</body>
</html>
