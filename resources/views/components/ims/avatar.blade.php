{{-- A round avatar with a person's initials (IMS-V2/DESIGN.md §7 AvatarStack): never a stock photo. --}}
@props([
    'name' => '',
    'size' => 'md',
])

@php
    $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $initials = mb_strtoupper(mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    $tone = abs(crc32((string) $name)) % 4;
@endphp

<span {{ $attributes->class(['ims-avatar', 'ims-avatar-'.$size, 'ims-avatar-tone-'.$tone]) }} title="{{ $name }}">
    <span aria-hidden="true">{{ $initials }}</span>
    <span class="sr-only">{{ $name }}</span>
</span>
