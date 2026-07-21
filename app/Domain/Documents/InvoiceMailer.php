<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Exceptions\InvoiceNotCompliant;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Ledger\AuditLogger;
use App\Mail\InvoiceIssued;
use Illuminate\Support\Facades\Mail;

/**
 * Sends an issued invoice to the customer (docs/specs/03 §4).
 *
 * Delivery is an audited event: BIR expects the trail to record what was
 * issued, to whom and when (RMC 5-2021 Annex B item 8). The compliance gate
 * runs inside the renderer, so a defective invoice is never sent.
 */
class InvoiceMailer
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function send(SalesInvoice $invoice, ?string $to = null): void
    {
        $invoice->loadMissing('partner');

        $recipient = $to ?? $invoice->partner?->email;

        if ($recipient === null || $recipient === '') {
            throw new InvoiceNotCompliant(
                "No email address for {$invoice->partner?->registered_name} — set one on the customer or pass a recipient."
            );
        }

        $mailable = InvoiceIssued::for($invoice);

        Mail::to($recipient)->send($mailable);

        $this->audit->record(
            event: 'invoice.emailed',
            auditableType: 'sales_invoices',
            auditableId: (int) $invoice->id,
            documentNumber: $invoice->invoice_number,
            after: ['to' => $recipient],
        );
    }
}
