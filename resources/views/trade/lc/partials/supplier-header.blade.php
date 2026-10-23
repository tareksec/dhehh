{{-- Papers the supplier issues (certificate, packing list, shipment advice): the supplier's name and address at the top. --}}
<div class="company-header">
    <div class="company-name">{{ $supplier?->company_name ?: $supplier?->name }}</div>
    <div>ADDRESS: {{ $supplier?->address }}@if ($supplier?->country), {{ $supplier->country }}@endif</div>
    @if ($supplier?->phone || $supplier?->email)
        <div>@if ($supplier?->phone)Tel: {{ $supplier->phone }}@endif @if ($supplier?->email) ,Email: {{ $supplier->email }}@endif</div>
    @endif
</div>
