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
            ['1400', 'Inventory', 'asset', 'debit', false, false],
            // Cash-basis only: goods have shipped (so Inventory must fall,
            // or the stock subledger stops tying to the GL) but the EXPENSE
            // waits for collection. This holds the cost in between (08 §2).
            ['1450', 'Deferred Cost of Goods Sold', 'asset', 'debit', false, true],
            ['1500', 'Equipment', 'asset', 'debit', false, false],
            // Contra-asset: type is asset but the normal balance is flipped
            // (01 §1.2) — the balance cache signs from normal_balance.
            ['1590', 'Accumulated Depreciation', 'asset', 'credit', true, false],
            ['2000', 'Accounts Payable', 'liability', 'credit', false, false],
            ['2100', 'Output VAT', 'liability', 'credit', false, false],
            ['2150', 'Withholding Tax Payable', 'liability', 'credit', false, false], // 1601EQ
            // D32 (draft answers 2026-07-24): a percentage-tax registrant files 2551Q
            // and needs somewhere to accrue it. An 8% elector files NEITHER
            // 2551Q nor this — the 8% is in lieu of percentage tax — so the
            // return set follows the date-effective `tax_regimes` row, not
            // the VAT flag.
            ['2160', 'Percentage Tax Payable', 'liability', 'credit', false, false], // 2551Q
            // Goods received but not yet invoiced: the receipt credits this
            // and the vendor's bill later clears it to A/P (08 §3, D15).
            ['2050', 'Goods Received Not Invoiced', 'liability', 'credit', false, true],
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
            ['5200', 'Cost of Goods Sold', 'expense', 'debit', false, false],
            // Shrinkage, spoilage, damage — where a count variance lands.
            ['5300', 'Inventory Shrinkage', 'expense', 'debit', false, false],
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
            'inventory' => '1400',
            'grni' => '2050',
            'cogs' => '5200',
            'deferred_cogs' => '1450',
            'inventory_adjustment' => '5300',
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
        ], ['INV', 'CM', 'DM', 'RC', 'CV', 'BILL', 'GR', 'ADJ', 'CNT', 'TRF']));

        // --- tax codes (data, never hard-coded rates — 01 §7) ------------
        DB::table('tax_codes')->insert([
            ['code' => 'OV12', 'kind' => 'output_vat', 'rate_bp' => 1200, 'account_id' => $accountId['2100'], 'default_atc' => null, 'effective_from' => '2024-01-01', 'effective_to' => null],
            ['code' => 'IV12', 'kind' => 'input_vat', 'rate_bp' => 1200, 'account_id' => $accountId['1200'], 'default_atc' => null, 'effective_from' => '2024-01-01', 'effective_to' => null],
        ]);

        // --- expanded-withholding rates (D25, research draft — licensed
        //     CPA sign-off still pending; see docs/cpa-briefing §4) ---------
        //
        // SOURCE HONESTY: these rates come from the AI-assisted research draft
        // (docs/cpa-briefing-draft-answers.md), NOT from a licensed Philippine
        // CPA. That document's own §4 lists the seeded ATC table as an item a
        // practitioner must still confirm against the current eBIRForms
        // library. So every row here is a researched default awaiting a
        // confirm-tick, not settled fact.
        //
        // These are the seven payment types the draft answered (fourteen rows
        // — each splits individual/juridical). They are NOT the whole ATC
        // library, and the gap is deliberate: `TaxResolver` throws
        // rather than guess, so a payment type that is not here fails loudly
        // at posting instead of withholding a made-up rate.
        //
        // Commissions: WI515/WC515 is a FLAT 10% pair, narrowly scoped to
        // brokers/agents (customs, insurance, stock, real-estate, immigration,
        // commercial) and agents of professional entertainers. GENERIC
        // non-employee commissions are professional fees → WI010/WI011, NOT
        // these. Two earlier errors, both corrected here: the first draft used
        // WI139/WI140, WC139/WC140 (wrong codes), and the follow-up seeded
        // WI515 at 5% with the professional-fee sworn-declaration logic (wrong
        // too — this code is flat 10%, no declaration gate). Scope AND rate
        // are on the reviewer's confirm list.
        //
        // Not seeded on purpose: WC157/WI157 and WC640/WI640 are GOVERNMENT
        // payment codes. Auto-assigning them to private purchases fails
        // alphalist validation, so they stay out until a government-payee
        // flow exists to claim them.
        //
        // The 5% individual professional rate and the 10% juridical rate are
        // conditional on a valid sworn declaration; absent or expired
        // resolves the HIGHER row (D26 — prospective, never retroactive).
        DB::table('atc_rates')->insert(array_map(fn (array $r) => [
            'atc_code' => $r[0],
            'description' => $r[1],
            'payee_type' => $r[2],
            'rate_bp' => $r[3],
            'threshold_centavos' => $r[4],
            'requires_sworn_declaration' => $r[5],
            'legal_basis' => $r[6],
            'effective_from' => '2018-01-01',
            'effective_to' => null,
        ], [
            ['WI010', 'Professional/talent fees — individual, within threshold', 'individual', 500, 300_000_000, true, 'RR 11-2018 / RR 14-2018'],
            ['WI011', 'Professional/talent fees — individual, above threshold', 'individual', 1000, null, false, 'RR 11-2018 / RR 14-2018'],
            ['WC010', 'Professional fees — juridical, within threshold', 'juridical', 1000, 72_000_000, true, 'RR 11-2018'],
            ['WC011', 'Professional fees — juridical, above threshold', 'juridical', 1500, null, false, 'RR 11-2018'],
            ['WI100', 'Rentals — real/personal property, individual', 'individual', 500, null, false, 'RR 2-98 §2.57.2(C)'],
            ['WC100', 'Rentals — real/personal property, juridical', 'juridical', 500, null, false, 'RR 2-98 §2.57.2(C)'],
            ['WI120', 'Contractors/subcontractors — individual', 'individual', 200, null, false, 'RR 2-98 §2.57.2'],
            ['WC120', 'Contractors/subcontractors — juridical', 'juridical', 200, null, false, 'RR 2-98 §2.57.2'],
            ['WI158', 'TWA → local supplier of GOODS, individual', 'individual', 100, null, false, 'RR 11-2018 (TWA only)'],
            ['WC158', 'TWA → local supplier of GOODS, juridical', 'juridical', 100, null, false, 'RR 11-2018 (TWA only)'],
            ['WI160', 'TWA → local supplier of SERVICES, individual', 'individual', 200, null, false, 'RR 11-2018 (TWA only)'],
            ['WC160', 'TWA → local supplier of SERVICES, juridical', 'juridical', 200, null, false, 'RR 11-2018 (TWA only)'],
            ['WI515', 'Broker/agent commissions (brokers, entertainer agents) — individual', 'individual', 1000, null, false, 'RR 11-2018 — research draft, reviewer to confirm scope + rate'],
            ['WC515', 'Broker/agent commissions — juridical', 'juridical', 1000, null, false, 'RR 11-2018 — research draft, reviewer to confirm scope + rate'],
        ]));

        // --- inventory reference data (08 §1) -----------------------------
        // A handful of PH-retail-typical units; the dimension exists from
        // day one even for a tenant that never touches inventory.
        DB::table('uoms')->insert(array_map(fn (array $u) => [
            'name' => $u[0], 'symbol' => $u[1], 'is_active' => true,
        ], [
            ['Piece', 'pc'], ['Box', 'box'], ['Case', 'case'], ['Pack', 'pack'],
            ['Kilogram', 'kg'], ['Gram', 'g'], ['Litre', 'L'], ['Millilitre', 'mL'],
        ]));

        // v1 ships ONE location; multi-location is supported everywhere in
        // the schema so adding the second is data, not a migration (08 §1).
        DB::table('locations')->insert([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
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
            'grni_account_id' => $accountId['2050'],
            // D17: refuse a sale that would drive stock negative.
            'negative_stock_policy' => 'block',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
