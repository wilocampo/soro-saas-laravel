<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit/debit notes + the non-VAT registration flag (docs/specs/03 §4).
 *
 * Once an invoice has been reported in a filed VAT return it can no longer
 * be cancelled — the adjustment must be a CREDIT NOTE that stands as its own
 * document with its own serial (RR 7-2024). Cancellation stays available
 * only while the period is still open; `DocumentCanceller` decides which.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mariadb = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        // Customer-facing serials get their own table rather than a sentinel
        // row in `document_sequences`: a journal book number is scoped to a
        // fiscal year (and FK-bound to one), while an invoice series is
        // CONTINUOUS — it never resets and carries across a system migration
        // (RMC 77-2024). Two different lifetimes, two different tables.
        Schema::create('serial_sequences', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('series', 24);              // INV, CM, DM, RC, CV, BILL
            $table->unsignedSmallInteger('branch_id'); // serials are per registered branch
            $table->string('prefix', 16)->default('');
            $table->unsignedTinyInteger('pad_width')->default(6);
            $table->unsignedBigInteger('last_value')->default(0);

            $table->unique(['series', 'branch_id'], 'uq_serial_series');
            $table->foreign('branch_id', 'fk_serial_branch')->references('id')->on('branches');
        });

        // `resets_yearly` was the earlier attempt at the same idea and is now
        // meaningless: everything left in document_sequences resets yearly.
        Schema::table('document_sequences', function (Blueprint $table) {
            $table->dropColumn('resets_yearly');
        });

        Schema::table('ledger_settings', function (Blueprint $table) {
            // A NON-VAT registrant (2551Q percentage tax) must never emit an
            // output-VAT line, and its invoices print "NON-VAT" (spec 03 §5).
            $table->boolean('is_vat_registered')->default(true)->after('base_currency');
            $table->unsignedSmallInteger('percentage_tax_rate_bp')->nullable()->after('is_vat_registered');
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('note_number', 40)->nullable()->unique();
            // Who the note is addressed to: a customer note moves A/R, a
            // vendor note moves A/P. `type` is the direction of the change.
            $table->enum('side', ['customer', 'vendor']);
            $table->enum('type', ['credit', 'debit']);
            $table->foreignId('partner_id')->constrained('partners');
            $table->date('note_date');
            $table->enum('status', ['draft', 'issued', 'cancelled'])->default('draft');
            // The document being adjusted — nullable for a standalone note.
            $table->string('applies_to_type', 32)->nullable();
            $table->unsignedBigInteger('applies_to_id')->nullable();
            $table->unsignedBigInteger('net_centavos')->default(0);
            $table->unsignedBigInteger('vat_centavos')->default(0);
            $table->unsignedBigInteger('total_centavos')->default(0);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('reason', 500);
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index(['applies_to_type', 'applies_to_id']);
            $table->index(['partner_id', 'side']);
            $table->index('note_date');
        });

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description');
            $table->decimal('quantity', 19, 4)->default(1);
            $table->decimal('unit_price', 19, 4)->default(0);
            $table->unsignedBigInteger('net_centavos')->default(0);
            $table->unsignedBigInteger('vat_centavos')->default(0);
            $table->unsignedBigInteger('tax_code_id')->nullable();
            // Customer notes default to Sales Returns & Allowances (contra
            // revenue) so GROSS sales stay intact for the books; vendor notes
            // credit back the original expense/asset account.
            $table->unsignedBigInteger('account_id');

            $table->unique(['credit_note_id', 'line_no']);
            $table->foreign('account_id')->references('id')->on('accounts');
        });

        if ($mariadb) {
            DB::unprepared('ALTER TABLE credit_notes ADD CONSTRAINT chk_note_total CHECK (total_centavos = net_centavos + vat_centavos)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('serial_sequences');

        Schema::table('document_sequences', function (Blueprint $table) {
            $table->boolean('resets_yearly')->default(true);
        });

        Schema::table('ledger_settings', function (Blueprint $table) {
            $table->dropColumn(['is_vat_registered', 'percentage_tax_rate_bp']);
        });
    }
};
