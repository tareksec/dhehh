{!! $textBody !!}
@if ($link)

{!! $link !!}
@endif

--
{!! $brand['name'] !!}
@foreach ($brand['lines'] as $line)
{!! $line !!}
@endforeach
