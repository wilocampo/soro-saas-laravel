@php
    $money = fn (int $centavos) => '₱ '.number_format($centavos / 100, 2);
@endphp
<x-mail::message>
# Invoice {{ $invoice->invoice_number }}

Dear {{ $invoice->partner->registered_name }},

Please find attached invoice **{{ $invoice->invoice_number }}** dated
{{ $invoice->invoice_date->format('d F Y') }} for **{{ $money($invoice->total_centavos) }}**@if ($invoice->vat_centavos > 0), inclusive of VAT@endif.

@if ($invoice->due_date)
Payment is due on **{{ $invoice->due_date->format('d F Y') }}**.
@endif

@if ($invoice->memo)
{{ $invoice->memo }}
@endif

Kindly quote the invoice number on your remittance.

Thanks,<br>
{{ $header['registered_name'] }}

<x-slot:subcopy>
{{ $header['registered_name'] }} — {{ $header['tin'] }}<br>
{{ $header['registered_address'] }}
</x-slot:subcopy>
</x-mail::message>
