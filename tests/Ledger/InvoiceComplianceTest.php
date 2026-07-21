<?php

namespace Tests\Ledger;

use App\Domain\Documents\DocumentCanceller;
use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Exceptions\InvoiceNotCompliant;
use App\Domain\Documents\InvoiceCompliance;
use App\Domain\Documents\InvoiceRenderer;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\SalesInvoiceLine;
use App\Domain\Ledger\Models\CompanyProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The eleven mandatory VAT-invoice fields and the five input-tax-fatal
 * omissions (NIRC Secs. 113(B) & 237 as amended; RR 7-2024 — docs/specs/03 §4).
 */
class InvoiceComplianceTest extends LedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A provisioned tenant starts with a placeholder profile; a real one
        // has completed registration.
        CompanyProfile::query()->where('id', 1)->update([
            'registered_name' => 'Soro Test Trading Inc.',
            'registered_address' => '123 Ayala Avenue, Makati City, 1226',
            'tin' => '246813579',
            'branch_code' => '000',
            'accn' => 'AC-2026-000123',
        ]);
    }

    private function buyer(array $overrides = []): Partner
    {
        return Partner::create(array_merge([
            'code' => 'C-300',
            'is_customer' => true,
            'registered_name' => 'Acme Trading Corp.',
            'address' => '9 Bonifacio High Street, Taguig',
            'tin' => '123456789',
            'branch_code' => '000',
            'is_vat_registered' => true,
        ], $overrides));
    }

    private function invoice(Partner $buyer, int $net = 1_000_000, int $vat = 120_000): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'partner_id' => $buyer->id,
            'invoice_date' => CarbonImmutable::now()->toDateString(),
            'status' => 'issued',
        ]);

        SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Management consulting services for July 2026',
            'quantity' => 1,
            'unit_price' => $net / 100,
            'net_centavos' => $net,
            'vat_centavos' => $vat,
            'account_id' => $this->accountId('4000'),
        ]);

        $invoice->load('lines');
        $invoice->recalculateTotals();
        $invoice->save();

        app(DocumentPoster::class)->post($invoice);

        return $invoice->fresh()->load(['lines', 'partner']);
    }

    public function test_a_complete_invoice_passes_with_no_fatal_findings(): void
    {
        $result = app(InvoiceCompliance::class)->check($this->invoice($this->buyer()));

        $this->assertSame([], $result['fatal']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_an_unconfigured_seller_profile_blocks_issuance(): void
    {
        // Back to what provisioning seeds.
        CompanyProfile::query()->where('id', 1)->update(['tin' => CompanyProfile::PLACEHOLDER_TIN]);

        $this->expectException(InvoiceNotCompliant::class);
        $this->expectExceptionMessageMatches('/placeholder/');

        app(InvoiceCompliance::class)->assertIssuable($this->invoice($this->buyer()));
    }

    public function test_a_missing_line_description_is_input_tax_fatal(): void
    {
        $invoice = $this->invoice($this->buyer());
        DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->id)->update(['description' => '']);

        $result = app(InvoiceCompliance::class)->check($invoice->fresh()->load(['lines', 'partner']));

        $this->assertNotEmpty($result['fatal']);
        $this->assertStringContainsString('nature of the service', $result['fatal'][0]);
    }

    /** Buyer identity is mandatory at ₱1,000 to a VAT-registered buyer. */
    public function test_missing_buyer_tin_is_fatal_above_the_threshold_and_a_warning_below(): void
    {
        $compliance = app(InvoiceCompliance::class);

        $big = $this->invoice($this->buyer(['code' => 'C-301', 'tin' => null]));
        $this->assertNotEmpty($compliance->check($big)['fatal']);

        // ₱5.00 — below the ₱1,000 threshold, so the same gap is a warning.
        $small = $this->invoice($this->buyer(['code' => 'C-302', 'tin' => null]), 500, 60);
        $result = $compliance->check($small);

        $this->assertSame([], $result['fatal']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_vatable_sales_without_a_vat_amount_are_fatal(): void
    {
        $invoice = $this->invoice($this->buyer(), 1_000_000, 0);

        $result = app(InvoiceCompliance::class)->check($invoice);

        $this->assertNotEmpty($result['fatal']);
        $this->assertStringContainsString('separate item', implode(' ', $result['fatal']));
    }

    public function test_a_missing_accn_warns_but_does_not_block(): void
    {
        CompanyProfile::query()->where('id', 1)->update(['accn' => null]);

        $result = app(InvoiceCompliance::class)->check($this->invoice($this->buyer()));

        $this->assertSame([], $result['fatal']);
        $this->assertStringContainsString('ACCN', implode(' ', $result['warnings']));
    }

    /** The rendered document must carry all eleven mandatory fields. */
    public function test_the_rendered_invoice_carries_every_mandatory_field(): void
    {
        $invoice = $this->invoice($this->buyer());

        $html = app(InvoiceRenderer::class)->html($invoice);

        foreach ([
            'Soro Test Trading Inc.',            // 2. seller registered name
            '123 Ayala Avenue',                  // 3. seller address
            'VAT REG. TIN 246-813-579-00000',    // 1. VAT statement + seller TIN
            $invoice->invoice_date->format('d M Y'),          // 4. date
            $invoice->invoice_number,            // 5. serial number
            'Management consulting services',    // 6. description
            'VATable sales',                     // 7. breakdown
            'VAT (12%)',                         // 8. VAT as a separate item
            'TOTAL AMOUNT DUE (VAT inclusive)',  // 9. total, VAT indicated
            'Acme Trading Corp.',                // 11. buyer name
            '123-456-789-00000',                 // 11. buyer TIN
            'ACCN AC-2026-000123',               // header/footer block
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "Rendered invoice is missing: {$needle}");
        }

        // Post-EOPT the Invoice is the single principal document; the words
        // "Official Receipt" must never appear on it (RR 7-2024).
        $this->assertStringNotContainsString('Official Receipt', $html);
        $this->assertStringContainsString('>INVOICE<', $html);
    }

    public function test_a_non_vat_invoice_prints_the_not_valid_for_input_tax_marker(): void
    {
        DB::table('ledger_settings')->where('id', 1)->update(['is_vat_registered' => false]);

        $html = app(InvoiceRenderer::class)->html($this->invoice($this->buyer(), 1_000_000, 0));

        $this->assertStringContainsString('NON-VAT INVOICE', $html);
        $this->assertStringContainsString('NOT VALID FOR CLAIM OF INPUT TAX', $html);
        $this->assertStringContainsString('NON-VAT TIN', $html);
    }

    public function test_zero_rated_and_exempt_sales_print_the_mandated_words(): void
    {
        $invoice = $this->invoice($this->buyer(), 1_000_000, 0);
        DB::table('sales_invoices')->where('id', $invoice->id)->update([
            'exempt_centavos' => 400_000,
            'zero_rated_centavos' => 600_000,
        ]);

        $html = app(InvoiceRenderer::class)->html($invoice->fresh()->load(['lines', 'partner']));

        $this->assertStringContainsString('VAT-EXEMPT SALE', $html);
        $this->assertStringContainsString('ZERO-RATED SALE', $html);
    }

    /**
     * The real thing, through headless Chrome. Skipped where the browser is
     * absent (CI compiles assets with PUPPETEER_SKIP_DOWNLOAD), so the HTML
     * assertions above stay the contract and this proves the last mile.
     */
    public function test_the_invoice_renders_to_an_actual_pdf(): void
    {
        if (! is_dir(base_path('node_modules/puppeteer'))) {
            $this->markTestSkipped('Requires puppeteer/Chrome — run `npm install` to exercise the PDF path.');
        }

        $invoice = $this->invoice($this->buyer());
        $path = sys_get_temp_dir()."/soro-invoice-{$invoice->id}.pdf";

        try {
            app(InvoiceRenderer::class)->pdf($invoice, $path);

            $this->assertFileExists($path);
            $this->assertSame('%PDF', file_get_contents($path, false, null, 0, 4));
            $this->assertGreaterThan(1000, filesize($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_a_cancelled_invoice_renders_as_cancelled_and_keeps_its_serial(): void
    {
        $invoice = $this->invoice($this->buyer());
        $number = $invoice->invoice_number;

        app(DocumentCanceller::class)->cancel($invoice, 'duplicate keying');

        $html = app(InvoiceRenderer::class)->html($invoice->fresh()->load(['lines', 'partner']));

        $this->assertStringContainsString('CANCELLED', $html);
        $this->assertStringContainsString($number, $html);
    }
}
