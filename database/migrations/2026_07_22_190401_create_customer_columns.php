<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier's billable columns — on `tenants`, NOT on `users` (docs/specs/10).
 *
 * The subscription belongs to the BUSINESS, not to whichever person happens
 * to have signed up: a tenant's staff change, its owner can leave, and the
 * books outlive both. Billing also lives entirely on the LANDLORD
 * connection, so a tenant's own database never holds card metadata.
 *
 * Published from `laravel/cashier` and retargeted; the two companion
 * migrations (subscriptions, subscription_items) are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']);
        });
    }
};
