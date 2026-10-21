{{-- Shared page-level CSS for the Purchase PI/CI/PL DomPDF views. Only the
     truly common rules live here (page size, base font, table borders) —
     each document's header/body layout differs enough to not force a
     shared partial beyond this. --}}
<style>
    @page { margin: 24px 28px; }
    body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.45; }
    table { border-collapse: collapse; width: 100%; }
    th, td { padding: 5px 6px; vertical-align: top; }
    .bordered th, .bordered td { border: 1px solid #111827; }
    .right { text-align: right; }
    .center { text-align: center; }
    .muted { color: #4b5563; }
    .bold { font-weight: 700; }
    .title { font-size: 18px; font-weight: 700; letter-spacing: 1px; text-align: center; margin: 10px 0 14px; text-transform: uppercase; }
    .company-header { text-align: center; margin-bottom: 10px; }
    .company-header .company-name { font-size: 14px; font-weight: 700; text-transform: uppercase; }
    .signature-block { margin-top: 10px; }
    .signature-block img { max-height: 60px; max-width: 220px; object-fit: contain; }
    .whitespace { white-space: pre-line; }
</style>
