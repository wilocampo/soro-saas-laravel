<?php

namespace Database\Seeders\Tenant;

use App\Domain\Ledger\Models\CompanyProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ledger core reference data for a freshly provisioned tenant (docs/specs/01):
 * the 5 account types, a minimal PH chart of accounts (incl. the four system
 * accounts), the main branch, the current Manila fiscal year with periods
 * 1–12 + adjustment period 13, per-book gapless sequences with the mandatory
 * '{TYPE}-{year_label}-' prefixes, tax codes, the account-role map, and the
 * ledger_settings singleton. Idempotent per tenant DB.
 */
class LedgerCoreSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('ledger_settings')->exists()) {
            return; // already seeded
        }

        $now = now();

        // --- account types (01 §1.1) -----------------------------------
        $types = [
            ['code' => 'asset', 'name' => 'Assets', 'normal_balance' => 'debit', 'statement' => 'balance_sheet', 'sort_order' => 1],
            ['code' => 'liability', 'name' => 'Liabilities', 'normal_balance' => 'credit', 'statement' => 'balance_sheet', 'sort_order' => 2],
            ['code' => 'equity', 'name' => 'Equity', 'normal_balance' => 'credit', 'statement' => 'balance_sheet', 'sort_order' => 3],
            ['code' => 'income', 'name' => 'Income', 'normal_balance' => 'credit', 'statement' => 'income_statement', 'sort_order' => 4],
            ['code' => 'expense', 'name' => 'Expenses', 'normal_balance' => 'debit', 'statement' => 'income_statement', 'sort_order' => 5],
        ];
        DB::table('account_types')->insert($types);
        $typeId = DB::table('account_types')->pluck('id', 'code');

        // --- chart of accounts (minimal; grows via UI) ------------------
        $accounts = [
            // code, name, type, normal, contra, system
            ['1000', 'Cash in Bank', 'asset', 'debit', false, false],
            ['1010', 'Cash on Hand', 'asset', 'debit', false, false],
            ['1100', 'Accounts Receivable', 'asset', 'debit', false, false],
            ['1150', 'Creditable Withholding Tax', 'asset', 'debit', false, false], // 2307 asset
            ['1200', 'Input VAT', 'asset', 'debit', false, false],
            ['1300', 'Advances to Suppliers', 'asset', 'debit', false, false],
            ['1500', 'Equipment', 'asset', 'debit', false, false],
            // Contra-asset: type is asset but the normal balance is flipped
            // (01 §1.2) — the balance cache signs from normal_balance.
            ['1590', 'Accumulated Depreciation', 'asset', 'credit', true, false],
            ['2000', 'Accounts Payable', 'liability', 'credit', false, false],
            ['2100', 'Output VAT', 'liability', 'credit', false, false],
            ['2150', 'Withholding Tax Payable', 'liability', 'credit', false, false], // 1601EQ
            // Cash held against future performance — never income until the
            // document it settles exists (02 §4.2).
            ['2200', 'Customer Deposits', 'liability', 'credit', false, false],
            ['3000', "Owner's Capital", 'equity', 'credit', false, false],
            ['3100', 'Opening Balance Equity', 'equity', 'credit', false, true],
            ['3200', 'Retained Earnings', 'equity', 'credit', false, true],
            ['3300', 'Income Summary', 'equity', 'credit', false, true],
            ['4000', 'Sales Revenue', 'income', 'credit', false, false],
            // Contra-revenue: credit notes land here so GROSS sales stay
            // intact in the books, which is what the BIR columns report.
            ['4100', 'Sales Returns and Allowances', 'income', 'debit', true, false],
            ['4900', 'Other Income', 'income', 'credit', false, false],
            ['5000', 'Operating Expense', 'expense', 'debit', false, false],
            ['5100', 'Depreciation Expense', 'expense', 'debit', false, false],
            ['5900', 'Rounding Gain/Loss', 'expense', 'debit', false, true],
        ];
        DB::table('accounts')->insert(array_map(fn (array $a, int $i) => [
            'account_type_id' => $typeId[$a[2]],
            'code' => $a[0],
            'name' => $a[1],
            'normal_balance' => $a[3],
            'is_contra' => $a[4],
            'is_postable' => true,
            'is_active' => true,
            'is_system' => $a[5],
            'sort_order' => $i,
            'created_at' => $now,
            'updated_at' => $now,
        ], $accounts, array_keys($accounts)));
        $accountId = DB::table('accounts')->pluck('id', 'code');

        // --- account-role map backing the AccountResolver (01 §7) --------
        DB::table('account_roles')->insert(collect([
            'cash' => '1000',
            'ar' => '1100',
            'ap' => '2000',
            'sales' => '4000',
            'output_vat' => '2100',
            'input_vat' => '1200',
            'cwt_asset' => '1150',
            'wht_payable' => '2150',
            'sales_returns' => '4100',
            'customer_deposit' => '2200',
            'vendor_advance' => '1300',
            'rounding' => '5900',
        ])->map(fn ($code, $role) => ['role' => $role, 'account_id' => $accountId[$code]])->values()->all());

        // --- main branch (BIR serials are per branch — 01 §7) ------------
        $branchId = DB::table('branches')->insertGetId([
            'branch_code' => '000', 'name' => 'Main', 'is_main' => true, 'is_active' => true,
        ]);

        // --- fiscal year (calendar, Asia/Manila — D18) -------------------
        $today = CarbonImmutable::now(); // app TZ is Asia/Manila
        $fyStart = $today->startOfYear();
        $fyEnd = $today->endOfYear()->startOfDay();
        $label = (string) $today->year;

        $fyId = DB::table('fiscal_years')->insertGetId([
            'year_label' => $label,
            'start_date' => $fyStart->toDateString(),
            'end_date' => $fyEnd->toDateString(),
            'status' => 'open',
        ]);

        $periods = [];
        for ($no = 1; $no <= 12; $no++) {
            $start = $fyStart->addMonths($no - 1);
            $periods[] = [
                'fiscal_year_id' => $fyId,
                'period_no' => $no,
                'start_date' => $start->toDateString(),
                'end_date' => $start->endOfMonth()->toDateString(),
                'status' => 'open',
            ];
        }
        // Period 13 — year-end adjustment window, end_date..end_date (01 §7).
        $periods[] = [
            'fiscal_year_id' => $fyId,
            'period_no' => 13,
            'start_date' => $fyEnd->toDateString(),
            'end_date' => $fyEnd->toDateString(),
            'status' => 'open',
        ];
        DB::table('fiscal_periods')->insert($periods);

        // --- gapless sequences, prefix MUST embed series + year (01 §3) --
        DB::table('document_sequences')->insert(array_map(fn (string $series) => [
            'document_type' => $series,
            'fiscal_year' => $fyId,
            'branch_id' => $branchId,
            'prefix' => "{$series}-{$label}-",
            'pad_width' => 6,
            'last_value' => 0,
        ], ['GJ', 'SJ', 'PJ', 'CRJ', 'CDJ', 'OB', 'YEC', 'REV']));

        // Customer-facing serials are a CONTINUOUS series: they never reset
        // at year end and carry on across a system migration (RMC 77-2024),
        // so the prefix must not embed a year.
        DB::table('serial_sequences')->insert(array_map(fn (string $series) => [
            'series' => $series,
            'branch_id' => $branchId,
            'prefix' => "{$series}-",
            'pad_width' => 6,
            'last_value' => 0,
        ], ['INV', 'CM', 'DM', 'RC', 'CV', 'BILL']));

        // --- tax codes (data, never hard-coded rates — 01 §7) ------------
        DB::table('tax_codes')->insert([
            ['code' => 'OV12', 'kind' => 'output_vat', 'rate_bp' => 1200, 'account_id' => $accountId['2100'], 'default_atc' => null, 'effective_from' => '2024-01-01', 'effective_to' => null],
            ['code' => 'IV12', 'kind' => 'input_vat', 'rate_bp' => 1200, 'account_id' => $accountId['1200'], 'default_atc' => null, 'effective_from' => '2024-01-01', 'effective_to' => null],
        ]);

        // --- BIR registration singleton (03 §1) ---------------------------
        // Deliberately a PLACEHOLDER: an unconfigured profile blocks invoice
        // issuance rather than printing a wrong TIN on a legal document.
        DB::table('company_profile')->insert([
            'id' => 1,
            'registered_name' => 'Unconfigured — set the registered name',
            'registered_address' => 'Unconfigured — set the registered address',
            'tin' => CompanyProfile::PLACEHOLDER_TIN,
            'branch_code' => '000',
            'is_twa' => false,
            'accn' => null,
            'registration_mode' => 'cas',
        ]);

        // --- settings singleton ------------------------------------------
        DB::table('ledger_settings')->insert([
            'id' => 1,
            'accounting_basis' => 'accrual',
            'posting_lock_date' => null,
            'current_fiscal_year_id' => $fyId,
            'base_currency' => 'PHP',
            'opening_balance_equity_account_id' => $accountId['3100'],
            'retained_earnings_account_id' => $accountId['3200'],
            'income_summary_account_id' => $accountId['3300'],
            'rounding_account_id' => $accountId['5900'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
