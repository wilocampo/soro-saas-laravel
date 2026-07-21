<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cutover documents (docs/specs/02 §4.2).
 *
 * An open invoice carried in from the previous system has NO journal entry
 * of its own: its money is already inside the A/R figure that
 * `OpeningBalanceService` posts, and posting it again would double the
 * receivable. The flag says so explicitly, so a null `journal_entry_id`
 * never has to be guessed at — cash-basis and cutover both produce one,
 * for entirely different reasons.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_invoices', 'vendor_bills'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('is_opening')->default(false)->after('status');
                // Part-paid before cutover. Kept apart from
                // `amount_paid_centavos`, which is a CACHE recomputed from
                // allocations — a refresh would otherwise erase history that
                // has no allocation row to rebuild it from.
                $blueprint->unsignedBigInteger('opening_paid_centavos')->default(0)->after('amount_paid_centavos');
            });
        }

        // Where an issued invoice is delivered.
        Schema::table('partners', function (Blueprint $blueprint) {
            $blueprint->string('email')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $blueprint) {
            $blueprint->dropColumn('email');
        });

        foreach (['sales_invoices', 'vendor_bills'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['is_opening', 'opening_paid_centavos']);
            });
        }
    }
};
