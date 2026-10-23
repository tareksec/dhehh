{{-- Letters the importer sends (LC Opening Letter, DRL): the letterhead image, or the business name and factory address. --}}
@if ($letterheadPath)
    <div class="letterhead"><img src="{{ $letterheadPath }}" alt="{{ $company?->name }}"></div>
@else
    <div class="company-header">
        <div class="company-name">{{ $company?->name }}</div>
        @if ($company?->factory_address ?: $company?->address)<div class="muted">{{ $company->factory_address ?: $company->address }}</div>@endif
        <div class="muted">{{ collect([$company?->phone, $company?->email])->filter()->implode(', ') }}</div>
    </div>
@endif
