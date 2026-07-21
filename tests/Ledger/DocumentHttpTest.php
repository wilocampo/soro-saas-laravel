<?php

namespace Tests\Ledger;

use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Ledger\Models\CompanyProfile;
use App\Http\Middleware\TenantMiddleware;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The document HTTP layer (docs/specs/02, 11). Tenant resolution is
 * middleware's job and is covered elsewhere; what matters here is that the
 * controllers validate their input and delegate the accounting rather than
 * doing any of it themselves.
 */
class DocumentHttpTest extends LedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Only tenant RESOLUTION is stubbed out — the suite already runs
        // against tenant schema on the default connection. Everything else,
        // route-model binding especially, stays on.
        $this->withoutMiddleware(TenantMiddleware::class);

        CompanyProfile::query()->where('id', 1)->update([
            'registered_name' => 'Soro Test Trading Inc.',
            'registered_address' => '123 Ayala Avenue, Makati City, 1226',
            'tin' => '246813579',
            'accn' => 'AC-2026-000123',
        ]);

        $this->actingAs(User::query()->firstOrFail());
    }

    private function customer(): Partner
    {
        return Partner::create([
            'code' => 'C-900',
            'is_customer' => true,
            'registered_name' => 'Acme Trading Corp.',
            'address' => '9 Bonifacio High Street, Taguig',
            'tin' => '123456789',
            'is_vat_registered' => true,
        ]);
    }

    public function test_issuing_an_invoice_posts_it_and_draws_a_serial(): void
    {
        $customer = $this->customer();

        $response = $this->post(route('invoices.store'), [
            'partner_id' => $customer->id,
            'invoice_date' => CarbonImmutable::now()->toDateString(),
            'lines' => [[
                'description' => 'Management consulting services',
                'quantity' => 1,
                'unit_price' => 10000,
                'net_centavos' => 1_000_000,
                'vat_centavos' => 120_000,
                'account_id' => $this->accountId('4000'),
            ]],
        ]);

        $invoice = SalesInvoice::query()->firstOrFail();

        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame('INV-000001', $invoice->invoice_number);
        $this->assertSame(1_120_000, (int) $invoice->total_centavos);
        $this->assertNotNull($invoice->journal_entry_id);
        $this->assertTrialBalanceZero();
    }

    public function test_a_line_without_a_description_is_rejected_before_anything_posts(): void
    {
        $customer = $this->customer();

        $this->post(route('invoices.store'), [
            'partner_id' => $customer->id,
            'invoice_date' => CarbonImmutable::now()->toDateString(),
            'lines' => [[
                'description' => '',
                'quantity' => 1,
                'unit_price' => 10000,
                'net_centavos' => 1_000_000,
                'vat_centavos' => 120_000,
                'account_id' => $this->accountId('4000'),
            ]],
        ])->assertSessionHasErrors('lines.0.description');

        $this->assertSame(0, SalesInvoice::count());
        // Crucially, the rejected invoice burned no serial.
        $this->assertSame(0, (int) DB::table('serial_sequences')->where('series', 'INV')->value('last_value'));
    }

    /** A non-compliant invoice must not consume a number either. */
    public function test_a_non_compliant_invoice_is_refused_without_burning_a_serial(): void
    {
        CompanyProfile::query()->where('id', 1)->update(['tin' => CompanyProfile::PLACEHOLDER_TIN]);

        $this->post(route('invoices.store'), [
            'partner_id' => $this->customer()->id,
            'invoice_date' => CarbonImmutable::now()->toDateString(),
            'lines' => [[
                'description' => 'Consulting',
                'quantity' => 1,
                'unit_price' => 10000,
                'net_centavos' => 1_000_000,
                'vat_centavos' => 120_000,
                'account_id' => $this->accountId('4000'),
            ]],
        ])->assertSessionHas('error');

        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame(0, (int) DB::table('serial_sequences')->where('series', 'INV')->value('last_value'));
    }

    public function test_a_partner_is_deactivated_never_deleted(): void
    {
        $customer = $this->customer();

        $this->delete(route('partners.destroy', $customer))->assertRedirect();

        // Posted documents keep referencing it, so the row survives.
        $this->assertDatabaseHas('partners', ['id' => $customer->id, 'is_active' => false]);
    }

    public function test_a_malformed_tin_is_rejected_with_a_useful_message(): void
    {
        $response = $this->post(route('partners.store'), [
            'code' => 'C-901',
            'registered_name' => 'Bad TIN Corp.',
            'is_customer' => true,
            'is_vendor' => false,
            'tin' => '12345',
            'taxpayer_type' => 'juridical',
            'payment_terms_days' => 0,
        ]);

        $response->assertSessionHasErrors('tin');
        $this->assertStringContainsString(
            'nine digits',
            session('errors')->first('tin')
        );
    }

    public function test_the_aging_report_renders_with_the_mandatory_bir_header(): void
    {
        $this->get(route('reports.aging'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/Aging')
                ->where('header.registered_name', 'Soro Test Trading Inc.')
                // Software name AND version are mandatory on every generated
                // report (RMC 5-2021 Annex B item 4).
                ->where('header.software', fn (string $software) => $software === config('compliance.software_name')
                    .' v'.config('compliance.software_version'))
                ->where('header.tin', 'VAT REG. TIN 246-813-579-00000')
            );
    }

    public function test_open_documents_are_offered_for_allocation(): void
    {
        $customer = $this->customer();

        $this->post(route('invoices.store'), [
            'partner_id' => $customer->id,
            'invoice_date' => CarbonImmutable::now()->toDateString(),
            'lines' => [[
                'description' => 'Consulting',
                'quantity' => 1,
                'unit_price' => 10000,
                'net_centavos' => 1_000_000,
                'vat_centavos' => 120_000,
                'account_id' => $this->accountId('4000'),
            ]],
        ]);

        $this->getJson(route('payments.open-documents', [
            'partner_id' => $customer->id,
            'direction' => 'received',
        ]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.number', 'INV-000001')
            ->assertJsonPath('0.outstanding_centavos', 1_120_000);
    }
}
