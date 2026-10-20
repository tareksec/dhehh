{{ $heading }}

@foreach ($lines as $line)
{{ $line }}
@endforeach
@if ($link)

{{ $link }}
@endif

— {{ $companyName }}
