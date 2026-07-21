<?php

namespace Tests\Ledger;

use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Exceptions\InvoiceNotCompliant;
use App\Domain\Documents\InvoiceMailer;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\SalesInvoiceLine;
use App\Domain\Ledger\Models\CompanyProfile;
use App\Mail\InvoiceIssued;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Emailing an issued invoice (docs/specs/03 §4) — audited, and gated. */
class InvoiceDeliveryTest extends LedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CompanyProfile::query()->where('id', 1)->update([
            'registered_name' => 'Soro Test Trading Inc.',
            'registered_address' => '123 Ayala Avenue, Makati City, 1226',
            'tin' => '246813579',
            'accn' => 'AC-2026-000123',
        ]);
    }

    private function invoice(?string $email): SalesInvoice
    {
        $partner = Partner::create([
            'code' => 'C-400',
            'is_customer' => true,
            'registered_name' => 'Acme Trading Corp.',
            'address' => '9 Bonifacio High Street, Taguig',
            'tin' => '123456789',
            'email' => $email,
            'is_vat_registered' => true,
        ]);

        $invoice = SalesInvoice::create([
            'partner_id' => $partner->id,
            'invoice_date' => CarbonImmutable::now()->toDateString(),
            'due_date' => CarbonImmutable::now()->addDays(30)->toDateString(),
            'status' => 'issued',
        ]);

        SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Management consulting services',
            'net_centavos' => 1_000_000,
            'vat_centavos' => 120_000,
            'account_id' => $this->accountId('4000'),
        ]);

        $invoice->load('lines');
        $invoice->recalculateTotals();
        $invoice->save();

        app(DocumentPoster::class)->post($invoice);

        return $invoice->fresh()->load(['lines', 'partner']);
    }

    public function test_sending_an_invoice_mails_the_customer_and_writes_an_audit_row(): void
    {
        if (! is_dir(base_path('node_modules/puppeteer'))) {
            $this->markTestSkipped('Requires puppeteer/Chrome to render the attachment.');
        }

        Mail::fake();
        $invoice = $this->invoice('ap@acme.test');

        app(InvoiceMailer::class)->send($invoice);

        Mail::assertSent(InvoiceIssued::class, function (InvoiceIssued $mail) use ($invoice) {
            return $mail->hasTo('ap@acme.test')
                && $mail->invoice->is($invoice)
                && str_contains($mail->envelope()->subject, $invoice->invoice_number)
                && str_contains($mail->envelope()->subject, 'Soro Test Trading Inc.');
        });

        $audit = DB::table('audit_log')->where('event', 'invoice.emailed')->first();
        $this->assertNotNull($audit, 'Delivery must be audited (RMC 5-2021 Annex B item 8).');
        $this->assertSame($invoice->invoice_number, $audit->document_number);
        $this->assertStringContainsString('ap@acme.test', (string) $audit->after_json);
    }

    public function test_a_customer_without_an_email_address_is_refused(): void
    {
        Mail::fake();

        $this->expectException(InvoiceNotCompliant::class);
        $this->expectExceptionMessageMatches('/No email address/');

        app(InvoiceMailer::class)->send($this->invoice(null));
    }

    public function test_an_explicit_recipient_overrides_the_customer_record(): void
    {
        if (! is_dir(base_path('node_modules/puppeteer'))) {
            $this->markTestSkipped('Requires puppeteer/Chrome to render the attachment.');
        }

        Mail::fake();

        app(InvoiceMailer::class)->send($this->invoice('ap@acme.test'), 'treasury@acme.test');

        Mail::assertSent(InvoiceIssued::class, fn (InvoiceIssued $mail) => $mail->hasTo('treasury@acme.test'));
    }
}
