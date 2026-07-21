<?php

namespace App\Domain\Documents;

use App\Domain\Compliance\ReportHeader;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Ledger\Models\CompanyProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Renders the BIR-faithful invoice (docs/specs/03 §4).
 *
 * HTML and PDF share ONE template, so what a customer sees on screen is the
 * document that gets filed. The compliance gate runs first: an invoice that
 * would cost the buyer its input-VAT claim is never rendered at all.
 */
class InvoiceRenderer
{
    public function __construct(
        private readonly InvoiceCompliance $compliance,
        private readonly ReportHeader $header,
    ) {}

    public function html(SalesInvoice $invoice): string
    {
        return $this->view($invoice)->render();
    }

    /** Writes a PDF to $path (headless Chrome via spatie/laravel-pdf). */
    public function pdf(SalesInvoice $invoice, string $path): string
    {
        $this->compliance->assertIssuable($invoice);

        Pdf::view('documents.invoice', $this->data($invoice))
            ->format('a4')
            ->save($path);

        return $path;
    }

    public function view(SalesInvoice $invoice): View
    {
        $this->compliance->assertIssuable($invoice);

        return view('documents.invoice', $this->data($invoice));
    }

    /** @return array<string, mixed> */
    private function data(SalesInvoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'partner']);

        $vatable = (int) $invoice->net_centavos
            - (int) $invoice->exempt_centavos
            - (int) $invoice->zero_rated_centavos;

        // The rate is DATA, read from the tax code in force on the invoice
        // date — never a hard-coded 12% (01 §7).
        $rateBp = DB::table('tax_codes')
            ->where('kind', 'output_vat')
            ->whereDate('effective_from', '<=', $invoice->invoice_date->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $invoice->invoice_date->toDateString()))
            ->orderByDesc('effective_from')
            ->value('rate_bp');

        return [
            'invoice' => $invoice,
            'header' => $this->header->for('Invoice '.$invoice->invoice_number),
            'vatableCentavos' => max(0, $vatable),
            'vatRateLabel' => $rateBp === null ? '—' : rtrim(rtrim(number_format($rateBp / 100, 2), '0'), '.').'%',
            'buyerTin' => $this->buyerTin($invoice),
            'money' => fn (int $centavos) => number_format($centavos / 100, 2),
        ];
    }

    private function buyerTin(SalesInvoice $invoice): string
    {
        $tin = $invoice->partner?->tin;

        if ($tin === null || $tin === '') {
            return '—';
        }

        return implode('-', [
            substr($tin, 0, 3),
            substr($tin, 3, 3),
            substr($tin, 6, 3),
            str_pad((string) ($invoice->partner->branch_code ?? '000'), 5, '0', STR_PAD_LEFT),
        ]);
    }

    /** Kept next to the renderer: the profile drives every field above. */
    public function sellerProfile(): CompanyProfile
    {
        return CompanyProfile::current();
    }
}
