<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord bookkeeping for per-tenant backups (docs/specs/10 §1).
 *
 * backup_catalog  — one row per dump artifact: label + software name/version
 *                   (RR 9-2009 §6.1 wording), checksum, tier, legal hold.
 * restore_test_log — BIR expects the artifact: proof that dumps actually
 *                   restore, written by tenants:backup-restore-test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_catalog', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('label');
            $table->string('software_name', 128);
            $table->string('software_version', 32);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            // operational dumps rotate on keep_days; compliance archives are
            // per-close/yearly and never age out automatically (10-yr horizon).
            $table->string('tier', 16)->default('operational');
            $table->boolean('legal_hold')->default(false);
            $table->string('status', 16)->default('completed'); // completed|failed
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tier', 'legal_hold']);
        });

        Schema::create('restore_test_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_catalog_id')->constrained('backup_catalog')->cascadeOnDelete();
            $table->boolean('checksum_verified');
            $table->string('outcome', 16); // passed|failed
            $table->text('details')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('operator', 128); // console user or "scheduler"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restore_test_log');
        Schema::dropIfExists('backup_catalog');
    }
};
