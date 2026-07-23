{{--
  BIR-faithful VAT invoice (docs/specs/03 §4). Every numbered comment below
  maps to one of the eleven mandatory fields of NIRC Secs. 113(B) & 237 as
  amended by RR 7-2024 — do not remove one without reading that section.

  Post-EOPT the INVOICE is the single principal VAT document for goods AND
  services (RR 7-2024). This template must never print "Official Receipt".
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10pt; color: #111; margin: 0; }
        .muted { color: #555; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .doc-title { font-size: 16pt; font-weight: 700; letter-spacing: .12em; }
        .serial { font-size: 13pt; font-weight: 700; }
        header { border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
        .grid { display: flex; justify-content: space-between; gap: 16px; }
        .party { border: 1px solid #999; padding: 8px; margin-bottom: 12px; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.lines th, table.lines td { border: 1px solid #999; padding: 5px 6px; }
        table.lines th { background: #f1f1f1; text-align: left; font-size: 9pt; text-transform: uppercase; }
        table.lines td.num { text-align: right; white-space: nowrap; }
        .totals { width: 58%; margin-left: auto; border-collapse: collapse; }
        .totals td { padding: 4px 6px; }
        .totals td.num { text-align: right; white-space: nowrap; }
        .totals tr.grand td { border-top: 2px solid #111; font-weight: 700; font-size: 11pt; }
        .marker { border: 2px solid #111; padding: 6px; margin: 10px 0; font-weight: 700; text-align: center; letter-spacing: .05em; }
        .cancelled { color: #b00; border-color: #b00; }
        footer { margin-top: 18px; border-top: 1px solid #999; padding-top: 6px; font-size: 8pt; color: #555; }
    </style>
</head>
<body>

{{-- Mandatory report header/footer block: registered name, address,
     TIN + branch, software name+version, user, timestamp (RMC 5-2021
     Annex B item 4). --}}
<header>
    <div class="grid">
        <div>
            {{-- 2. seller registered name --}}
            <div class="bold">{{ $header['registered_name'] }}</div>
            {{-- 3. seller registered business address --}}
            <div class="muted">{{ $header['registered_address'] }}</div>
            {{-- 1. VAT-registered statement + seller TIN incl. branch code --}}
            <div class="bold">{{ $header['tin'] }}</div>
            {{--
              ACCN on the FACE of the document — CPA-confirmed required
              (D35, 2026-07-24). It also prints in the footer as part of the
              RMC 5-2021 Annex B item 4 generation block, but that block is a
              different obligation (software/user/timestamp on every report);
              this one identifies the registered system to whoever holds the
              paper, so it belongs with the seller's registration details.
            --}}
            @if ($header['accn'])
                <div class="muted">ACCN {{ $header['accn'] }}</div>
            @endif
        </div>
        <div class="right">
            <div class="doc-title">{{ $header['is_vat_registered'] === '1' ? 'INVOICE' : 'NON-VAT INVOICE' }}</div>
            {{-- 5. serial number, printed prominently --}}
            <div class="serial">No. {{ $invoice->invoice_number }}</div>
            {{-- 4. date of transaction --}}
            <div>Date: {{ $invoice->invoice_date->format('d M Y') }}</div>
            @if ($invoice->due_date)
                <div class="muted">Due: {{ $invoice->due_date->format('d M Y') }}</div>
            @endif
        </div>
    </div>
</header>

@if ($invoice->status === 'cancelled')
    <div class="marker cancelled">CANCELLED — {{ $invoice->cancellation_reason }}</div>
@endif

{{-- 11. buyer registered name, address and TIN (mandatory for sales of
     ₱1,000 or more to a VAT-registered buyer). --}}
<div class="party">
    <div class="grid">
        <div>
            <div class="muted">SOLD TO</div>
            <div class="bold">{{ $invoice->partner->registered_name }}</div>
            <div>{{ $invoice->partner->address ?: '—' }}</div>
        </div>
        <div class="right">
            <div class="muted">TIN</div>
            <div class="bold">{{ $buyerTin }}</div>
        </div>
    </div>
</div>

{{-- 6. quantity, unit cost, description of goods / nature of service --}}
<table class="lines">
    <thead>
        <tr>
            <th style="width:6%">#</th>
            <th>Description / Nature of service</th>
            <th style="width:10%" class="right">Qty</th>
            <th style="width:16%" class="right">Unit price</th>
            <th style="width:18%" class="right">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->lines as $line)
            <tr>
                <td>{{ $line->line_no }}</td>
                <td>{{ $line->description }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 4), '0'), '.') }}</td>
                <td class="num">{{ number_format((float) $line->unit_price, 2) }}</td>
                <td class="num">{{ $money($line->net_centavos) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    {{-- 7. breakdown into VATable / VAT-exempt / zero-rated --}}
    <tr><td>VATable sales</td><td class="num">{{ $money($vatableCentavos) }}</td></tr>
    @if ($invoice->exempt_centavos > 0)
        <tr><td>VAT-exempt sales</td><td class="num">{{ $money($invoice->exempt_centavos) }}</td></tr>
    @endif
    @if ($invoice->zero_rated_centavos > 0)
        <tr><td>Zero-rated sales</td><td class="num">{{ $money($invoice->zero_rated_centavos) }}</td></tr>
    @endif
    {{-- 8. VAT as a separate line item --}}
    <tr><td>VAT ({{ $vatRateLabel }})</td><td class="num">{{ $money($invoice->vat_centavos) }}</td></tr>
    {{-- 9. total amount payable, indicating that it includes VAT --}}
    <tr class="grand">
        <td>TOTAL AMOUNT DUE {{ $invoice->vat_centavos > 0 ? '(VAT inclusive)' : '' }}</td>
        <td class="num">₱ {{ $money($invoice->total_centavos) }}</td>
    </tr>
</table>

{{-- 10. the words printed prominently for exempt / zero-rated sales --}}
@if ($invoice->exempt_centavos > 0)
    <div class="marker">VAT-EXEMPT SALE</div>
@endif
@if ($invoice->zero_rated_centavos > 0)
    <div class="marker">ZERO-RATED SALE</div>
@endif
@if ($header['is_vat_registered'] !== '1')
    <div class="marker">THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX</div>
@endif

@if ($invoice->memo)
    <p class="muted">{{ $invoice->memo }}</p>
@endif

<footer>
    <div>{{ $header['registered_name'] }} — {{ $header['tin'] }}</div>
    <div>
        {{ $header['software'] }}
        @if ($header['accn']) · ACCN {{ $header['accn'] }} @endif
        · generated by {{ $header['generated_by'] }} on {{ $header['generated_at'] }}
    </div>
</footer>

</body>
</html>
