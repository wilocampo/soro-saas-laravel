<?php

namespace Tests\Ledger;

use App\Domain\Documents\AgingService;
use App\Domain\Documents\DocumentBalances;
use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Import\ChartOfAccountsImporter;
use App\Domain\Documents\Import\OpenDocumentImporter;
use App\Domain\Documents\Import\PartnerImporter;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\Payment;
use App\Domain\Documents\Models\PaymentAllocation;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Ledger\OpeningBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cutover import (docs/specs/02 §4.2). The load-bearing invariant: imported
 * open documents are the DETAIL behind an opening balance, never a second
 * posting of the same money.
 */
class CsvImportTest extends LedgerTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'soro-import-').'.csv';
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    public function test_partners_import_and_reimport_updates_rather_than_duplicates(): void
    {
        $importer = app(PartnerImporter::class);

        $result = $importer->import($this->csv(<<<'CSV'
        code,registered_name,is_customer,is_vendor,tin,branch_code,address,is_vat_registered,taxpayer_type
        C-001,Acme Trading Corp.,yes,no,123-456-789,000,"9 Bonifacio High Street, Taguig",yes,juridical
        V-001,Supplier Inc.,no,yes,987654321,000,"1 Ortigas Center, Pasig",yes,juridical
        CSV));

        $this->assertTrue($result->isClean(), implode('; ', array_column($result->errors, 'message')));
        $this->assertSame(2, $result->created);

        // The dashes in the TIN are stripped, not rejected.
        $this->assertSame('123456789', Partner::where('code', 'C-001')->value('tin'));

        $again = $importer->import($this->csv(<<<'CSV'
        code,registered_name,is_customer,is_vendor,tin,is_vat_registered
        C-001,Acme Trading Corporation,yes,no,123456789,yes
        CSV));

        $this->assertSame(0, $again->created);
        $this->assertSame(1, $again->updated);
        $this->assertSame(2, Partner::count());
        $this->assertSame('Acme Trading Corporation', Partner::where('code', 'C-001')->value('registered_name'));
    }

    public function test_bad_partner_rows_are_reported_individually_not_fatal(): void
    {
        $result = app(PartnerImporter::class)->import($this->csv(<<<'CSV'
        code,registered_name,is_customer,is_vendor,tin
        C-010,Good Customer,yes,no,111222333
        C-011,Bad TIN,yes,no,12345
        C-012,,yes,no,444555666
        C-013,Neither Role,no,no,777888999
        CSV));

        $this->assertSame(1, $result->created, 'The good row must still land.');
        $this->assertCount(3, $result->errors);
        $this->assertSame([2, 3, 4], array_column($result->errors, 'row'));
        $this->assertStringContainsString('nine digits', $result->errors[0]['message']);
    }

    public function test_chart_of_accounts_import_links_parents_and_demotes_them_to_rollups(): void
    {
        $result = app(ChartOfAccountsImporter::class)->import($this->csv(<<<'CSV'
        code,name,type,normal_balance,parent_code,is_postable
        6100,Utilities - Electricity,expense,debit,6000,yes
        6000,Utilities,expense,debit,,yes
        6200,Utilities - Water,expense,debit,6000,yes
        CSV));

        $this->assertTrue($result->isClean(), implode('; ', array_column($result->errors, 'message')));
        $this->assertSame(3, $result->created);

        $parent = DB::table('accounts')->where('code', '6000')->first();
        // A child appearing ABOVE its parent in the file still links.
        $this->assertSame($parent->id, (int) DB::table('accounts')->where('code', '6100')->value('parent_id'));
        // A roll-up is never a posting target.
        $this->assertFalse((bool) $parent->is_postable);
    }

    public function test_an_account_cannot_be_its_own_parent(): void
    {
        $result = app(ChartOfAccountsImporter::class)->import($this->csv(<<<'CSV'
        code,name,type,parent_code
        6300,Recursive,expense,6300
        CSV));

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('its own parent', $result->errors[0]['message']);
    }

    /** A posted account's type is history — the import must not restate it. */
    public function test_an_account_with_journal_lines_cannot_change_its_type(): void
    {
        $this->posting()->post($this->draft([['1000', 50_000, 0], ['4000', 0, 50_000]]));

        $result = app(ChartOfAccountsImporter::class)->import($this->csv(<<<'CSV'
        code,name,type,normal_balance
        4000,Sales Revenue,expense,debit
        CSV));

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('frozen', $result->errors[0]['message']);
    }

    /**
     * The cutover invariant: imported documents carry NO journal entry, and
     * their outstanding total equals the A/R opening balance to the centavo.
     */
    public function test_open_documents_are_detail_behind_the_opening_balance_not_a_second_posting(): void
    {
        app(PartnerImporter::class)->import($this->csv(<<<'CSV'
        code,registered_name,is_customer,is_vendor,tin,is_vat_registered
        C-100,Alpha Corp.,yes,no,111111111,yes
        C-101,Beta Inc.,yes,no,222222222,yes
        CSV));

        $today = CarbonImmutable::now();
        $old = $today->subDays(40)->toDateString();
        $recent = $today->subDays(10)->toDateString();

        // 11,200.00 open + 5,600.00 with 1,000.00 already collected = 15,800.00
        $result = app(OpenDocumentImporter::class)->import($this->csv(<<<CSV
        type,number,partner_code,document_date,due_date,description,net_centavos,vat_centavos,amount_paid_centavos
        sales_invoice,LEGACY-0091,C-100,{$old},{$old},Carried from prior system,1000000,120000,0
        sales_invoice,LEGACY-0092,C-101,{$recent},{$recent},Carried from prior system,500000,60000,100000
        CSV));

        $this->assertTrue($result->isClean(), implode('; ', array_column($result->errors, 'message')));
        $this->assertSame(2, $result->created);

        // Nothing was posted — that is the point.
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertNull(SalesInvoice::where('invoice_number', 'LEGACY-0091')->value('journal_entry_id'));
        // The prior system's serial is preserved verbatim (RMC 77-2024).
        $this->assertTrue(SalesInvoice::where('invoice_number', 'LEGACY-0091')->exists());

        // Now post the opening balance that these documents detail.
        app(OpeningBalanceService::class)->post(['1100' => 1_580_000], $today);

        $tie = app(OpenDocumentImporter::class)->verifyAgainstOpeningBalance('sales_invoice', '1100');
        $this->assertSame(0, $tie['difference'], 'Imported detail must tie to the A/R opening balance.');
        $this->assertSame(1_580_000, $tie['documents']);

        // And the aging report agrees, from the subledger.
        $aging = app(AgingService::class)->receivables($today);
        $this->assertSame(1_580_000, $aging['totals']['total']);
        $this->assertSame(1_120_000, $aging['totals']['31_60']);  // due 40 days ago
        $this->assertSame(460_000, $aging['totals']['1_30']);     // due 10 days ago, part-paid
    }

    /** A carried-in part-payment survives a balance refresh. */
    public function test_a_cutover_part_payment_is_not_erased_by_a_refresh(): void
    {
        app(PartnerImporter::class)->import($this->csv(<<<'CSV'
        code,registered_name,is_customer,is_vendor,is_vat_registered
        C-200,Gamma Ltd.,yes,no,yes
        CSV));

        $today = CarbonImmutable::now();
        app(OpenDocumentImporter::class)->import($this->csv(<<<CSV
        type,number,partner_code,document_date,net_centavos,vat_centavos,amount_paid_centavos
        sales_invoice,LEGACY-0100,C-200,{$today->toDateString()},1000000,120000,120000
        CSV));

        $invoice = SalesInvoice::where('invoice_number', 'LEGACY-0100')->first();
        $partner = Partner::where('code', 'C-200')->first();

        // Collect 200,000 more against it, which triggers a refresh.
        $payment = Payment::create([
            'direction' => 'received',
            'partner_id' => $partner->id,
            'payment_date' => $today->toDateString(),
            'amount_centavos' => 200_000,
            'cash_account_id' => $this->accountId('1000'),
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'allocatable_type' => 'sales_invoice',
            'allocatable_id' => $invoice->id,
            'applied_centavos' => 200_000,
        ]);
        app(DocumentPoster::class)->post($payment->load('allocations'));

        // 120,000 carried + 200,000 new = 320,000 paid; 800,000 still open.
        $this->assertSame(320_000, (int) $invoice->fresh()->amount_paid_centavos);
        $this->assertSame(800_000, app(DocumentBalances::class)->outstandingCentavos($invoice->fresh()));
    }

    public function test_a_fully_settled_document_is_refused_at_import(): void
    {
        app(PartnerImporter::class)->import($this->csv(<<<'CSV'
        code,registered_name,is_customer,is_vendor,is_vat_registered
        C-300,Delta Co.,yes,no,yes
        CSV));

        $today = CarbonImmutable::now()->toDateString();
        $result = app(OpenDocumentImporter::class)->import($this->csv(<<<CSV
        type,number,partner_code,document_date,net_centavos,vat_centavos,amount_paid_centavos
        sales_invoice,LEGACY-0200,C-300,{$today},1000000,120000,1120000
        sales_invoice,LEGACY-0201,C-300,{$today},1000000,120000,2000000
        sales_invoice,LEGACY-0202,C-999,{$today},1000000,120000,0
        CSV));

        $this->assertSame(0, $result->created);
        $this->assertCount(3, $result->errors);
        $this->assertStringContainsString('only OPEN documents', $result->errors[0]['message']);
        $this->assertStringContainsString('exceeds the document total', $result->errors[1]['message']);
        $this->assertStringContainsString('does not exist', $result->errors[2]['message']);
    }

    public function test_a_missing_required_column_fails_loudly(): void
    {
        $this->expectExceptionMessageMatches('/missing required column/');

        app(PartnerImporter::class)->import($this->csv("code,name\nC-1,No registered_name column\n"));
    }
}
