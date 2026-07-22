<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data Privacy Act registration on the company profile (RA 10173;
 * docs/specs/10 §2 — Phase 5).
 *
 * This system holds a lot of other people's personal data: counterparty
 * TINs and addresses, the 2307 certificates, the alphalist, and every actor
 * name in the audit log. A tenant running it needs its own NPC registration
 * and a named Data Protection Officer. We cannot verify either — so these
 * are ATTESTATIONS the operator records, and the go-live gate reads them.
 *
 * `go_live_at` marks the moment the tenant declared itself ready; it is
 * recorded for the audit trail rather than enforced, because the tenant's
 * compliance officer is not us.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profile', function (Blueprint $table) {
            $table->boolean('npc_registered')->default(false)->after('registration_mode');
            $table->string('npc_registration_number', 64)->nullable()->after('npc_registered');
            $table->string('dpo_name')->nullable()->after('npc_registration_number');
            $table->string('dpo_email')->nullable()->after('dpo_name');
            $table->timestamp('go_live_at')->nullable()->after('dpo_email');
        });
    }

    public function down(): void
    {
        Schema::table('company_profile', function (Blueprint $table) {
            $table->dropColumn([
                'npc_registered', 'npc_registration_number', 'dpo_name', 'dpo_email', 'go_live_at',
            ]);
        });
    }
};
