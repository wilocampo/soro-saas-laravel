<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Physical counts, adjustments and transfers (docs/specs/08 §1, §4.1).
 *
 * The count is the "advanced" part of the module: the system FREEZES a
 * snapshot quantity per line when counting starts, so the variance is
 * measured against what the books said at that moment — not against a
 * figure that kept moving while staff walked the aisles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mariadb = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->nullable()->unique();
            $table->unsignedSmallInteger('location_id');
            // draft → counting → review → approved. Approved is immutable.
            $table->enum('status', ['draft', 'counting', 'review', 'approved', 'cancelled'])->default('draft');
            // Blind counting hides the system quantity from the counter, which
            // is the only way the count is evidence rather than confirmation.
            $table->boolean('is_blind')->default(true);
            $table->timestamp('counting_started_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('stock_adjustment_id')->nullable();
            $table->string('memo', 500)->nullable();
            $table->timestamps();

            $table->index(['location_id', 'status']);
            $table->foreign('location_id', 'fk_count_location')->references('id')->on('locations');
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items');
            // Frozen when counting starts — the books' opinion at that instant.
            $table->decimal('snapshot_qty', 16, 5)->default(0);
            $table->decimal('counted_qty', 16, 5)->nullable();
            $table->decimal('variance_qty', 16, 5)->default(0);
            $table->decimal('unit_cost', 19, 6)->default(0);
            $table->bigInteger('variance_centavos')->default(0);   // signed
            $table->string('note', 255)->nullable();

            $table->unique(['stock_count_id', 'item_id'], 'uq_count_item');
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->nullable()->unique();
            $table->unsignedSmallInteger('location_id');
            $table->date('adjustment_date');
            $table->enum('reason', ['shrinkage', 'spoilage', 'damage', 'found', 'correction', 'count']);
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->bigInteger('total_value_centavos')->default(0);   // signed
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('stock_count_id')->nullable();
            $table->string('memo', 500)->nullable();
            $table->timestamps();

            $table->index(['location_id', 'reason']);
            $table->foreign('location_id', 'fk_adjustment_location')->references('id')->on('locations');
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items');
            $table->decimal('qty_delta', 16, 5);          // signed: + found, − short
            $table->decimal('unit_cost', 19, 6);
            $table->bigInteger('value_centavos');         // signed
            $table->unsignedBigInteger('lot_id')->nullable();

            $table->unique(['stock_adjustment_id', 'line_no'], 'uq_adjustment_line');
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->nullable()->unique();
            $table->unsignedSmallInteger('from_location_id');
            $table->unsignedSmallInteger('to_location_id');
            $table->date('transfer_date');
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->string('memo', 500)->nullable();
            $table->timestamps();

            $table->foreign('from_location_id', 'fk_transfer_from')->references('id')->on('locations');
            $table->foreign('to_location_id', 'fk_transfer_to')->references('id')->on('locations');
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items');
            $table->decimal('qty', 16, 5);

            $table->unique(['stock_transfer_id', 'line_no'], 'uq_transfer_line');
        });

        if ($mariadb) {
            DB::unprepared('ALTER TABLE stock_adjustment_lines ADD CONSTRAINT chk_adjustment_nonzero CHECK (qty_delta <> 0)');
            DB::unprepared('ALTER TABLE stock_transfer_lines ADD CONSTRAINT chk_transfer_positive CHECK (qty > 0)');
            // Moving stock to where it already is records nothing and would
            // produce a self-cancelling pair of movements.
            DB::unprepared('ALTER TABLE stock_transfers ADD CONSTRAINT chk_transfer_distinct CHECK (from_location_id <> to_location_id)');
        }
    }

    public function down(): void
    {
        foreach ([
            'stock_transfer_lines', 'stock_transfers',
            'stock_adjustment_lines', 'stock_adjustments',
            'stock_count_lines', 'stock_counts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
