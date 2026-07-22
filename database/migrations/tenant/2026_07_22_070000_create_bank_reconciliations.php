<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manual bank reconciliation (Phase 3; bank feeds are v2).
 *
 * The operator marks which BOOK lines appear on the bank statement. What is
 * left is deposits in transit and outstanding cheques. If the two sides
 * still disagree after that, the difference is a bank-only item — a charge
 * or interest credit that has not been booked — and the answer is to POST
 * it, never to plug the reconciliation. So there is no adjustment column
 * here by design: `complete()` refuses while a difference remains.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mariadb = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cash_account_id');
            $table->date('statement_date');
            // Signed: an overdrawn account legitimately shows a credit balance.
            $table->bigInteger('statement_closing_centavos');
            $table->enum('status', ['draft', 'completed'])->default('draft');
            $table->string('notes', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamps();

            $table->unique(['cash_account_id', 'statement_date'], 'uq_recon_account_date');
            $table->foreign('cash_account_id', 'fk_recon_account')->references('id')->on('accounts');
        });

        // Which book lines the operator saw on the statement. A row here
        // means "cleared"; absence means in transit or outstanding.
        Schema::create('bank_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->unsignedBigInteger('journal_line_id');
            $table->timestamp('cleared_at')->nullable();

            $table->unique(['bank_reconciliation_id', 'journal_line_id'], 'uq_recon_line');
            $table->foreign('journal_line_id', 'fk_recon_journal_line')->references('id')->on('journal_lines');
        });

        if ($mariadb) {
            // A completed reconciliation must record who signed it off.
            DB::unprepared(
                'ALTER TABLE bank_reconciliations ADD CONSTRAINT chk_recon_completed_meta CHECK ('
                ."status <> 'completed' OR (completed_at IS NOT NULL AND completed_by IS NOT NULL))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_lines');
        Schema::dropIfExists('bank_reconciliations');
    }
};
