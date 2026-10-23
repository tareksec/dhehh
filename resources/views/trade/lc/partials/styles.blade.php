{{-- The ERP's purchase-paper styles, plus what the letters and certificates need. --}}
@include('purchases.documents.partials.styles')
<style>
    body { font-size: 12px; }
    .letterhead img { width: 100%; max-height: 150px; object-fit: contain; }
    .letter p { margin: 0 0 10px; }
    .upper { text-transform: uppercase; }
    .para { margin: 0 0 9px; text-align: justify; }
    .sign img { max-height: 70px; max-width: 220px; }
    .list td { padding: 2px 6px 2px 0; }
</style>
