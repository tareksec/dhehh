<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $mailSubject }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;font-family:'Segoe UI',Roboto,Arial,'Noto Sans Bengali','Hind Siliguri',sans-serif;">
                    {{-- Header: the logo (or the name) on white, a line in the site's colour under it. --}}
                    <tr>
                        <td style="padding:20px 28px;border-bottom:4px solid {{ $brand['color'] }};">
                            @if ($brand['logo'])
                                <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" height="40" style="display:block;height:40px;max-height:40px;width:auto;border:0;">
                            @else
                                <span style="font-size:20px;font-weight:700;color:#111827;">{{ $brand['name'] }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;font-size:16px;line-height:1.6;color:#1f2937;">
                            {!! $htmlBody !!}
                            @if ($link)
                                <p style="margin:24px 0 0;">
                                    <a href="{{ $link }}" style="display:inline-block;background:{{ $brand['color'] }};color:{{ $brand['foreground'] }};padding:12px 24px;border-radius:8px;font-weight:600;text-decoration:none;">{{ __('Open') }}</a>
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.6;color:#6b7280;">
                            <strong style="color:#374151;">{{ $brand['name'] }}</strong>
                            @foreach ($brand['lines'] as $line)
                                <br>{{ $line }}
                            @endforeach
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
