{{-- App\Support\PanelErrorPages and TurnOffPagesWithMissingTables. Self-contained on purpose (inline styles, no
     layout, no database): it is shown when something else is broken. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? __('This page could not be opened') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f8fafc; --card: #ffffff; --text: #0f172a; --muted: #475569; --line: #e2e8f0; --accent: #d97706; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b1120; --card: #111827; --text: #f1f5f9; --muted: #94a3b8; --line: #1f2937; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Bengali", sans-serif; }
        .card { width: 100%; max-width: 480px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 28px 24px; }
        .icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: color-mix(in srgb, var(--accent) 15%, transparent); color: var(--accent); font-size: 24px; font-weight: 700; }
        h1 { font-size: 20px; margin: 16px 0 8px; }
        p { margin: 0 0 12px; line-height: 1.55; color: var(--muted); }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
        a.btn { display: inline-block; padding: 10px 16px; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 14px; border: 1px solid var(--line); color: var(--text); }
        a.btn.primary { background: var(--accent); border-color: var(--accent); color: #ffffff; }
    </style>
</head>
<body>
    <main class="card">
        <div class="icon" aria-hidden="true">!</div>
        <h1>{{ $title ?? __('This page could not be opened') }}</h1>
        <p>{{ $message ?? __('There is a problem in this part of the app. The other sections keep working, so you can carry on with them.') }}</p>
        <p>{{ __('The error has been recorded and reported to the administrators.') }}</p>
        <div class="actions">
            @unless ($onHome)
                <a class="btn primary" href="{{ url($home) }}">{{ __('Go to the dashboard') }}</a>
            @endunless
            <a class="btn" href="{{ $retryUrl }}">{{ __('Try again') }}</a>
            <a class="btn" href="javascript:history.back()">{{ __('Go back') }}</a>
        </div>
    </main>
</body>
</html>
