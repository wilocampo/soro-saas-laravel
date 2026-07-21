<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-2 subledger (docs/specs/02 §2, 03). Documents are subledger rows
 * that REFERENCE the journal entry they produced — they never carry a
 * running balance (02 §0). BIR counterparty fields (TIN + branch code,
 * VAT registration, sworn-declaration validity) are captured from day one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mariadb = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        // Customer-facing serials are a CONTINUOUS series that never resets
        // and continues across system migration (RMC 77-2024) — unlike the
        // internal journal book numbers, which reset per fiscal year.
        Schema::table('document_sequences', function (Blueprint $table) {
            $table->boolean('resets_yearly')->default(true)->after('pad_width');
        });

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_vendor')->default(false);
            // BIR counterparty identity (spec 03: TIN + branch code, registered name)
            $table->string('registered_name');
            $table->string('trade_name')->nullable();
            $table->char('tin', 9)->nullable();
            $table->string('branch_code', 5)->default('000');
            $table->string('address', 500)->nullable();
            $table->boolean('is_vat_registered')->default(false);
            $table->enum('taxpayer_type', ['individual', 'juridical'])->default('juridical');
            // Absent/expired sworn declaration → resolve the HIGHER EWT rate (01 §7)
            $table->date('sworn_declaration_valid_until')->nullable();
            $table->string('default_atc_code', 16)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_customer', 'is_active']);
            $table->index(['is_vendor', 'is_active']);
            $table->index('tin');
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            // The Invoice is the single PRINCIPAL VAT document post-EOPT for
            // goods AND services; an OR is supplementary (RR 7-2024).
            $table->string('invoice_number', 40)->nullable()->unique();
            $table->enum('document_class', ['principal', 'supplementary'])->default('principal');
            $table->foreignId('partner_id')->constrained('partners');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->enum('status', ['draft', 'issued', 'paid', 'cancelled'])->default('draft');
            $table->unsignedBigInteger('net_centavos')->default(0);
            $table->unsignedBigInteger('vat_centavos')->default(0);
            $table->unsignedBigInteger('exempt_centavos')->default(0);
            $table->unsignedBigInteger('zero_rated_centavos')->default(0);
            $table->unsignedBigInteger('total_centavos')->default(0);
            $table->unsignedBigInteger('amount_paid_centavos')->default(0);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('memo', 500)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'status']);
            $table->index('invoice_date');
        });

        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description');
            // Sub-centavo precision lives in the SUBLEDGER only; the rounded
            // centavo total is what posts to the journal (01 §0).
            $table->decimal('quantity', 19, 4)->default(1);
            $table->decimal('unit_price', 19, 4)->default(0);
            $table->unsignedBigInteger('net_centavos')->default(0);
            $table->unsignedBigInteger('vat_centavos')->default(0);
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->unsignedBigInteger('account_id');   // revenue account

            $table->unique(['sales_invoice_id', 'line_no']);
            $table->foreign('account_id')->references('id')->on('accounts');
        });

        Schema::create('vendor_bills', function (Blueprint $table) {
            $table->id();
            $table->string('bill_number', 40)->nullable();          // the VENDOR's number
            $table->string('reference', 40)->nullable()->unique();  // ours
            $table->foreignId('partner_id')->constrained('partners');
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->enum('status', ['draft', 'open', 'paid', 'cancelled'])->default('draft');
            $table->unsignedBigInteger('net_centavos')->default(0);
            $table->unsignedBigInteger('input_vat_centavos')->default(0);
            $table->unsignedBigInteger('ewt_centavos')->default(0);   // withheld from the vendor
            $table->unsignedBigInteger('total_centavos')->default(0); // payable after EWT
            $table->unsignedBigInteger('amount_paid_centavos')->default(0);
            $table->string('atc_code', 16)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('memo', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'status']);
            $table->index('bill_date');
        });

        Schema::create('vendor_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_bill_id')->constrained('vendor_bills')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description');
            $table->decimal('quantity', 19, 4)->default(1);
            $table->decimal('unit_price', 19, 4)->default(0);
            $table->unsignedBigInteger('net_centavos')->default(0);
            $table->unsignedBigInteger('input_vat_centavos')->default(0);
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->unsignedBigInteger('account_id');   // expense/asset account

            $table->unique(['vendor_bill_id', 'line_no']);
            $table->foreign('account_id')->references('id')->on('accounts');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 40)->nullable()->unique();
            $table->enum('direction', ['received', 'paid']);   // collection vs disbursement
            $table->foreignId('partner_id')->constrained('partners');
            $table->date('payment_date');
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->unsignedBigInteger('amount_centavos');       // cash actually moved
            $table->unsignedBigInteger('ewt_centavos')->default(0); // withheld by/from us
            $table->string('atc_code', 16)->nullable();
            $table->unsignedBigInteger('cash_account_id');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('memo', 500)->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'direction']);
            $table->index('payment_date');
            $table->foreign('cash_account_id')->references('id')->on('accounts');
        });

        // Allocation model (partials, over-payments, advances) — a payment
        // applies to zero or more documents; unapplied cash is an advance.
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('allocatable_type', 32);   // sales_invoice | vendor_bill
            $table->unsignedBigInteger('allocatable_id');
            $table->unsignedBigInteger('applied_centavos');

            $table->index(['allocatable_type', 'allocatable_id']);
        });

        if ($mariadb) {
            DB::unprepared('ALTER TABLE payment_allocations ADD CONSTRAINT chk_alloc_positive CHECK (applied_centavos > 0)');
            DB::unprepared('ALTER TABLE payments ADD CONSTRAINT chk_payment_positive CHECK (amount_centavos > 0)');
        }
    }

    public function down(): void
    {
        foreach ([
            'payment_allocations', 'payments',
            'vendor_bill_lines', 'vendor_bills',
            'sales_invoice_lines', 'sales_invoices',
            'partners',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('document_sequences', function (Blueprint $table) {
            $table->dropColumn('resets_yearly');
        });
    }
};
