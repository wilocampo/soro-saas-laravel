<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provisioning state machine (docs/specs/10 §2): routing/middleware must
 * only serve tenants whose DB finished provisioning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('provisioning_status', 16)->default('pending')->after('is_active');
            $table->index('provisioning_status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['provisioning_status']);
            $table->dropColumn('provisioning_status');
        });
    }
};
