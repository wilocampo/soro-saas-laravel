<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger core (docs/specs/01 §1, §6, §7) — tenant DB, no tenant_id columns.
 *
 * Portability: schema-builder DDL runs on both MariaDB (real) and sqlite
 * (fast suite / provisioning tests). CHECK constraints, the audit_log
 * partitioning, and everything trigger-shaped are MariaDB-only — sqlite
 * relies on the app layer (PostingService is the primary enforcer, 01 §2.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        $mariadb = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('account_types', function (Blueprint $table) {
            $table->tinyIncrements('id');
            $table->string('code', 24)->unique();
            $table->string('name', 64);
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->enum('statement', ['balance_sheet', 'income_statement']);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('account_type_id');
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('accounts');
            $table->enum('normal_balance', ['debit', 'credit']); // from type; flipped if contra
            $table->boolean('is_contra')->default(false);
            $table->boolean('is_postable')->default(true);      // only leaf accounts accept lines
            $table->boolean('is_active')->default(true);        // deactivate, never delete
            $table->boolean('is_system')->default(false);       // OBE / RE / Income Summary / Rounding
            // BIR placeholders (Phase 4; docs/specs/03):
            $table->string('bir_tax_type', 24)->nullable();
            $table->string('bir_atc_code', 16)->nullable();
            $table->string('bir_fs_line', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('parent_id', 'idx_accounts_parent');
            $table->index(['is_active', 'is_postable'], 'idx_accounts_active_postable');
            $table->foreign('account_type_id', 'fk_accounts_type')->references('id')->on('account_types');
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('year_label', 9)->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->unique(['start_date', 'end_date'], 'uq_fy_range');
        });

        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->unsignedSmallInteger('fiscal_year_id');
            $table->unsignedTinyInteger('period_no');   // 1..12 (+13 = year-end adjustment)
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'closed', 'locked'])->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->unique(['fiscal_year_id', 'period_no'], 'uq_period');
            $table->index(['start_date', 'end_date'], 'idx_period_dates');
            $table->foreign('fiscal_year_id', 'fk_period_fy')->references('id')->on('fiscal_years');
        });

        Schema::create('ledger_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->default(1)->primary();
            $table->enum('accounting_basis', ['accrual', 'cash'])->default('accrual');
            $table->date('posting_lock_date')->nullable();  // entry_date <= this is rejected
            $table->unsignedSmallInteger('current_fiscal_year_id')->nullable();
            $table->char('base_currency', 3)->default('PHP');
            $table->foreignId('opening_balance_equity_account_id')->nullable()->constrained('accounts');
            $table->foreignId('retained_earnings_account_id')->nullable()->constrained('accounts');
            $table->foreignId('income_summary_account_id')->nullable()->constrained('accounts');
            $table->foreignId('rounding_account_id')->nullable()->constrained('accounts');
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('branch_code', 5)->default('000')->unique();
            $table->string('name', 128);
            $table->string('address', 500)->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('document_type', 24);        // 'GJ','SJ','PJ','CRJ','CDJ','OB','YEC','REV'
            $table->unsignedSmallInteger('fiscal_year'); // references fiscal_years.id (non-calendar safe)
            $table->unsignedSmallInteger('branch_id');   // BIR serials are per branch (01 §7)
            $table->string('prefix', 16)->default('');
            $table->unsignedTinyInteger('pad_width')->default(6);
            $table->unsignedBigInteger('last_value')->default(0); // last COMMITTED number

            $table->unique(['document_type', 'fiscal_year', 'branch_id'], 'uq_seq');
            $table->foreign('fiscal_year', 'fk_seq_fy')->references('id')->on('fiscal_years');
            $table->foreign('branch_id', 'fk_seq_branch')->references('id')->on('branches');
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number', 40)->nullable()->unique('uq_entry_number');
            $table->date('entry_date');
            $table->unsignedSmallInteger('fiscal_period_id');
            $table->enum('journal_book', [
                'general', 'sales', 'purchase', 'cash_receipts',
                'cash_disbursements', 'opening_balance', 'year_end_close', 'reversal',
            ])->default('general');
            $table->string('description', 500)->nullable();
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique('uq_idempotency');
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries');
            $table->foreignId('reversed_by_entry_id')->nullable()->constrained('journal_entries');
            $table->string('reversal_reason')->nullable();
            $table->unsignedBigInteger('total_debit_centavos')->default(0);
            $table->unsignedBigInteger('total_credit_centavos')->default(0);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->char('posting_hash', 64)->nullable(); // sha256 of canonical lines
            $table->timestamps();

            $table->index('status', 'idx_je_status');
            $table->index('entry_date', 'idx_je_date');
            $table->index('fiscal_period_id', 'idx_je_period');
            $table->index(['source_type', 'source_id'], 'idx_je_source');
            $table->foreign('fiscal_period_id', 'fk_je_period')->references('id')->on('fiscal_periods');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->unsignedBigInteger('debit_centavos')->default(0);
            $table->unsignedBigInteger('credit_centavos')->default(0);
            $table->string('memo')->nullable();
            $table->date('entry_date');                 // denormalized from header (immutable)
            $table->unsignedSmallInteger('fiscal_period_id');
            // optional dimensions / BIR carry-forward:
            $table->string('partner_type', 24)->nullable();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->unsignedBigInteger('tax_base_centavos')->nullable();
            $table->string('atc_code', 16)->nullable();
            $table->unsignedBigInteger('department_id')->nullable(); // reserved dimension (v2)
            $table->unsignedBigInteger('project_id')->nullable();    // reserved dimension (v2)
            $table->string('source_line_ref', 64)->nullable();

            $table->unique(['journal_entry_id', 'line_no'], 'uq_jl_entry_line');
            $table->index('account_id', 'idx_jl_account');
            $table->index(['fiscal_period_id', 'account_id', 'debit_centavos', 'credit_centavos'], 'idx_jl_period_account');
            $table->index(['partner_type', 'partner_id'], 'idx_jl_partner');
            $table->foreign('account_id', 'fk_jl_account')->references('id')->on('accounts');
            $table->foreign('fiscal_period_id', 'fk_jl_period')->references('id')->on('fiscal_periods');
        });

        Schema::create('account_period_balances', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id');
            $table->unsignedSmallInteger('fiscal_period_id');
            $table->bigInteger('opening_signed')->default(0);   // signed centavos
            $table->unsignedBigInteger('period_debits')->default(0);
            $table->unsignedBigInteger('period_credits')->default(0);
            $table->bigInteger('closing_signed')->default(0);
            $table->timestamp('rebuilt_at')->nullable();

            $table->primary(['account_id', 'fiscal_period_id']);
            $table->index('fiscal_period_id', 'idx_apb_period');
            $table->foreign('account_id', 'fk_apb_account')->references('id')->on('accounts');
            $table->foreign('fiscal_period_id', 'fk_apb_period')->references('id')->on('fiscal_periods');
        });

        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16);                 // 'OV12','IV12','PT3','EXEMPT','ZR0'
            $table->enum('kind', ['output_vat', 'input_vat', 'percentage_tax', 'exempt', 'zero_rated']);
            $table->unsignedSmallInteger('rate_bp');    // 12% = 1200 — date-effective, never hard-coded
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->string('default_atc', 16)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->unique(['code', 'effective_from'], 'uq_tax_code_eff');
        });

        Schema::create('atc_rates', function (Blueprint $table) {
            $table->id();
            $table->string('atc_code', 16);
            $table->string('description');
            $table->enum('payee_type', ['individual', 'juridical']);
            $table->unsignedSmallInteger('rate_bp');
            $table->unsignedBigInteger('threshold_centavos')->nullable();
            $table->boolean('requires_sworn_declaration')->default(false);
            $table->string('legal_basis', 64)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->unique(['atc_code', 'payee_type', 'effective_from'], 'uq_atc_eff');
        });

        Schema::create('company_profile', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->default(1)->primary();
            $table->string('registered_name');
            $table->string('registered_address', 500);
            $table->char('tin', 9);
            $table->string('branch_code', 5)->default('000');
            $table->enum('taxpayer_classification', ['micro', 'small', 'medium', 'large'])->nullable();
            $table->boolean('is_twa')->default(false);
            $table->date('twa_effective_date')->nullable();
            $table->string('accn', 64)->nullable();      // Acknowledgement Certificate (go-live gate)
            $table->date('accn_issued_at')->nullable();
            $table->enum('registration_mode', ['manual', 'loose_leaf', 'cba', 'cas'])->nullable();
        });

        Schema::create('tax_regimes', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->enum('regime', ['vat', 'percentage_tax', 'eight_percent']);
            $table->date('effective_from')->unique('uq_regime_from');
            $table->date('effective_to')->nullable();
        });

        Schema::create('account_roles', function (Blueprint $table) {
            $table->string('role', 32)->primary();      // 'ar','ap','sales','output_vat',...
            $table->foreignId('account_id')->constrained('accounts');
        });

        // audit_log: composite PK (id, occurred_at) — the partition column must
        // be in every unique key (01 §6 design correction). Raw DDL on MariaDB
        // (partitioned); plain table on sqlite (no partitioning support).
        if ($mariadb) {
            DB::unprepared(<<<'SQL'
                CREATE TABLE audit_log (
                  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  occurred_at DATETIME(6) NOT NULL,
                  actor_user_id BIGINT UNSIGNED NULL, actor_name VARCHAR(255) NULL, actor_ip VARBINARY(16) NULL,
                  event VARCHAR(48) NOT NULL,
                  auditable_type VARCHAR(64) NULL, auditable_id BIGINT UNSIGNED NULL,
                  document_number VARCHAR(40) NULL,
                  before_json JSON NULL, after_json JSON NULL, context_json JSON NULL,
                  prev_hash CHAR(64) NULL, row_hash CHAR(64) NOT NULL,
                  PRIMARY KEY (id, occurred_at),
                  KEY idx_audit_actor (actor_user_id), KEY idx_audit_target (auditable_type, auditable_id),
                  KEY idx_audit_event_time (event, occurred_at), KEY idx_audit_time (occurred_at)
                ) ENGINE=InnoDB
                PARTITION BY RANGE (YEAR(occurred_at)) (
                  PARTITION p2026 VALUES LESS THAN (2027),
                  PARTITION p2027 VALUES LESS THAN (2028),
                  PARTITION pmax  VALUES LESS THAN MAXVALUE
                )
                SQL);
        } else {
            Schema::create('audit_log', function (Blueprint $table) {
                $table->id();
                $table->dateTime('occurred_at', 6);
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_name')->nullable();
                $table->binary('actor_ip')->nullable();
                $table->string('event', 48);
                $table->string('auditable_type', 64)->nullable();
                $table->unsignedBigInteger('auditable_id')->nullable();
                $table->string('document_number', 40)->nullable();
                $table->json('before_json')->nullable();
                $table->json('after_json')->nullable();
                $table->json('context_json')->nullable();
                $table->char('prev_hash', 64)->nullable();
                $table->char('row_hash', 64);

                $table->index('actor_user_id', 'idx_audit_actor');
                $table->index(['auditable_type', 'auditable_id'], 'idx_audit_target');
                $table->index(['event', 'occurred_at'], 'idx_audit_event_time');
                $table->index('occurred_at', 'idx_audit_time');
            });
        }

        // CHECK constraints — MariaDB ≥ 10.5 enforces them (D22); sqlite path
        // relies on PostingService + the XOR check is asserted app-side too.
        if ($mariadb) {
            // NOTE (deviation from 01 §1.2): MariaDB rejects CHECK constraints
            // that reference an AUTO_INCREMENT column (error 1901), so
            // chk_accounts_no_self_parent cannot exist here — self-parenting
            // is app-enforced when the CoA UI assigns parents (Phase 2).
            DB::unprepared('ALTER TABLE ledger_settings ADD CONSTRAINT chk_ledger_settings_singleton CHECK (id = 1)');
            DB::unprepared('ALTER TABLE company_profile ADD CONSTRAINT chk_company_singleton CHECK (id = 1)');
            DB::unprepared('ALTER TABLE journal_lines ADD CONSTRAINT chk_jl_debit_xor_credit CHECK ((debit_centavos > 0) XOR (credit_centavos > 0))');
            DB::unprepared("ALTER TABLE journal_entries ADD CONSTRAINT chk_je_posted_meta CHECK (status <> 'posted' OR (posted_at IS NOT NULL AND posted_by IS NOT NULL AND entry_number IS NOT NULL))");
            DB::unprepared("ALTER TABLE journal_entries ADD CONSTRAINT chk_je_void_meta CHECK (status <> 'void' OR (voided_at IS NOT NULL AND voided_by IS NOT NULL))");
        }
    }

    public function down(): void
    {
        foreach ([
            'account_roles', 'tax_regimes', 'company_profile', 'atc_rates', 'tax_codes',
            'audit_log', 'account_period_balances', 'journal_lines', 'journal_entries',
            'document_sequences', 'branches', 'ledger_settings', 'fiscal_periods',
            'fiscal_years', 'accounts', 'account_types',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
