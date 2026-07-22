<?php

namespace Tests\Ledger;

use App\Domain\Ledger\Models\CompanyProfile;
use App\Domain\Ledger\OpeningBalanceService;
use App\Http\Middleware\TenantMiddleware;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** The report routes: on-screen rendering and the three export formats. */
class ReportHttpTest extends LedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(TenantMiddleware::class);

        CompanyProfile::query()->where('id', 1)->update([
            'registered_name' => 'Soro Test Trading Inc.',
            'registered_address' => '123 Ayala Avenue, Makati City, 1226',
            'tin' => '246813579',
            'accn' => 'AC-2026-000123',
        ]);

        $this->actingAs(User::query()->firstOrFail());

        app(OpeningBalanceService::class)->post(
            ['1000' => 500_000, '3000' => 500_000],
            CarbonImmutable::now()->startOfYear()
        );
    }

    public static function reportRoutes(): array
    {
        return [
            'trial balance' => ['reports.trial-balance', 'Reports/TrialBalance'],
            'balance sheet' => ['reports.balance-sheet', 'Reports/BalanceSheet'],
            'income statement' => ['reports.income-statement', 'Reports/IncomeStatement'],
            'general ledger' => ['reports.general-ledger', 'Reports/GeneralLedger'],
            'journal' => ['reports.journal', 'Reports/Journal'],
        ];
    }

    #[DataProvider('reportRoutes')]
    public function test_each_report_renders_with_the_mandatory_header(string $routeName, string $component): void
    {
        $this->get(route($routeName))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component($component)
                ->where('header.registered_name', 'Soro Test Trading Inc.')
                ->where('header.accn', 'AC-2026-000123')
                ->where('header.software', config('compliance.software_name').' v'.config('compliance.software_version'))
            );
    }

    #[DataProvider('reportRoutes')]
    public function test_each_report_exports_to_every_format(string $routeName): void
    {
        foreach ([
            'csv' => 'text/csv',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ] as $format => $mime) {
            $response = $this->get(route($routeName, ['format' => $format]));

            $response->assertOk();
            $this->assertStringContainsString($mime, (string) $response->headers->get('content-type'));
            $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        }
    }

    public function test_an_unsupported_export_format_is_a_404(): void
    {
        $this->get(route('reports.trial-balance', ['format' => 'docx']))->assertNotFound();
    }

    public function test_an_unknown_journal_book_is_a_404(): void
    {
        $this->get(route('reports.journal', ['book' => 'imaginary']))->assertNotFound();
    }

    public function test_the_balance_sheet_reports_its_own_tie_out(): void
    {
        $this->get(route('reports.balance-sheet'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tie.ties', true)
                ->where('report.balanced', true)
            );
    }

    public function test_a_period_can_be_chosen_explicitly(): void
    {
        $firstPeriodId = (int) DB::table('fiscal_periods')->where('period_no', 1)->value('id');

        $this->get(route('reports.trial-balance', ['period_id' => $firstPeriodId]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('periodId', $firstPeriodId));
    }

    public function test_an_invalid_date_range_is_rejected(): void
    {
        $this->get(route('reports.journal', ['from' => '2026-06-01', 'to' => '2026-01-01']))
            ->assertSessionHasErrors('to');
    }
}
