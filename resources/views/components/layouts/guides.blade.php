<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Guides' }}</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #111827;
            background: #f8fafc;
        }
        * { box-sizing: border-box; }
        body { margin: 0; }
        a { color: inherit; }
        .nav {
            position: sticky;
            top: 0;
            z-index: 10;
            padding: 16px clamp(16px, 4vw, 48px);
            background: rgb(248 250 252 / .92);
            border-bottom: 1px solid #e5e7eb;
            backdrop-filter: blur(12px);
            font-size: 15px;
            font-weight: 900;
        }
        .layout {
            display: grid;
            grid-template-columns: 17rem minmax(0, 1fr);
            gap: 28px;
            max-width: 1120px;
            margin: 0 auto;
            padding: 28px clamp(16px, 4vw, 48px) 64px;
        }
        .sidebar {
            padding: 16px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            align-self: start;
            position: sticky;
            top: 72px;
        }
        .sidebar h2 {
            margin: 14px 0 6px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #64748b;
        }
        .sidebar h2:first-child { margin-top: 0; }
        .sidebar ul { list-style: none; margin: 0; padding: 0; }
        .sidebar li a {
            display: block;
            padding: 6px 8px;
            border-radius: 6px;
            font-size: 14px;
            color: #334155;
            text-decoration: none;
        }
        .sidebar li a:hover { background: #f1f5f9; }
        .sidebar li a.active { background: #fef3c7; color: #92400e; font-weight: 700; }
        main { min-width: 0; }
        h1 { margin: 0 0 6px; font-size: 30px; line-height: 1.15; }
        .meta { color: #94a3b8; font-size: 13px; margin-bottom: 22px; }
        .content { font-size: 15px; line-height: 1.75; color: #1e293b; }
        .content h2 { font-size: 21px; margin-top: 28px; }
        .content h3 { font-size: 18px; margin-top: 22px; }
        .content pre { background: #0f172a; color: #e2e8f0; padding: 14px; border-radius: 8px; overflow-x: auto; }
        .content code { background: #f1f5f9; padding: 2px 5px; border-radius: 4px; font-size: .9em; }
        .content pre code { background: none; padding: 0; }
        .content table { border-collapse: collapse; width: 100%; margin: 16px 0; }
        .content th, .content td { border: 1px solid #e2e8f0; padding: 8px 10px; text-align: left; font-size: 14px; }
        .content th { background: #f8fafc; }
        .empty { padding: 32px; text-align: center; color: #64748b; background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 10px; }
        @media (max-width: 760px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: static; }
        }
    </style>
</head>
<body>
    <nav class="nav">
        <a href="{{ route('guides.index') }}">Guides</a>
    </nav>
    <div class="layout">
        {{ $slot }}
    </div>
</body>
</html>
