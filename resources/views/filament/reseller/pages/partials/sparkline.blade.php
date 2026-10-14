{{--
    A tiny trend line for the dashboard's overview cards. $values is a list of
    numbers (oldest first); $class sets the colour through currentColor.
--}}
@php
    $values = array_map('floatval', array_values($values));
    $count = count($values);
    $max = $count ? max($values) : 0;
    $points = [];

    foreach ($values as $index => $value) {
        $x = $count > 1 ? round($index * (100 / ($count - 1)), 2) : 0;
        // 2..26 on a 28-high canvas; a flat zero series sits on the baseline.
        $y = $max > 0 ? round(26 - ($value / $max) * 24, 2) : 26;
        $points[] = $x.','.$y;
    }

    $line = implode(' ', $points);
    $area = $line !== '' ? $line.' 100,28 0,28' : '';
@endphp

@if ($count > 1)
    <svg viewBox="0 0 100 28" preserveAspectRatio="none" class="h-8 w-full {{ $class ?? '' }}" aria-hidden="true">
        <polygon points="{{ $area }}" fill="currentColor" fill-opacity="0.12" />
        <polyline points="{{ $line }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
    </svg>
@endif
