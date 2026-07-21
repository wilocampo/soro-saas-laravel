<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications for TENANT users (docs/specs/10 §5).
 *
 * A tenant's users live in the tenant database, so their notifications must
 * too — `HandleInertiaRequests` shares an unread count on every page render,
 * and that query runs on whichever connection is current. Without this table
 * every tenant page 500s the moment the connection swaps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
