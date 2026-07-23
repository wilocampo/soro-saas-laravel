<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D27 (CPA, 2026-07-24) — Top Withholding Agent status is bounded at BOTH ends.
 *
 * We modelled TWA status as a flag plus a start date, on the assumption that
 * a designation, once made, ran until someone changed the flag. The CPA
 * corrected that: de-listing takes effect by publication exactly as listing
 * does, so the status has an end date too.
 *
 * Without this column, a de-listed client keeps withholding 1%/2% from its
 * suppliers forever — money taken from a payee that the payor had no
 * authority to take, on every bill, silently.
 *
 * The obligation also runs from the 1st of the month FOLLOWING publication,
 * which is why both dates are stored rather than derived: the publication
 * date and the effective date are different days, and only the second one
 * decides whether a given bill withholds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profile', function (Blueprint $table) {
            $table->date('twa_effective_to')->nullable()->after('twa_effective_date');
        });
    }

    public function down(): void
    {
        Schema::table('company_profile', function (Blueprint $table) {
            $table->dropColumn('twa_effective_to');
        });
    }
};
