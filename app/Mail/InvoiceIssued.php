<?php

namespace App\Mail;

use App\Domain\Compliance\ReportHeader;
use App\Domain\Documents\InvoiceRenderer;
use App\Domain\Documents\Models\SalesInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers an issued invoice to the customer with the BIR-faithful PDF
 * attached (docs/specs/03 §4).
 *
 * NOT queued: rendering needs headless Chrome and the tenant's database
 * connection, and a queued job would have to re-establish both. Phase 5
 * moves this to the tenant-aware queue once that path is proven end to end.
 */
class InvoiceIssued extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var array<string, string|null> */
    public array $header;

    public function __construct(
        public readonly SalesInvoice $invoice,
        private readonly string $pdfPath,
    ) {
        $this->header = app(ReportHeader::class)->for('Invoice '.$invoice->invoice_number);
    }

    /** Renders the PDF to a temp file, then builds the mailable around it. */
    public static function for(SalesInvoice $invoice): self
    {
        $path = tempnam(sys_get_temp_dir(), 'soro-invoice-').'.pdf';

        app(InvoiceRenderer::class)->pdf($invoice, $path);

        return new self($invoice, $path);
    }

    public function envelope(): Envelope
    {
        $seller = app(ReportHeader::class)->for('')['registered_name'];

        return new Envelope(
            subject: "Invoice {$this->invoice->invoice_number} from {$seller}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invoice-issued');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->pdfPath)
                ->as("{$this->invoice->invoice_number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
