<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot tracking with expiry and FEFO (docs/specs/08 §1 "inventory_lots").
 *
 * The design is lifted from the developer's ninetails prior art, which spec
 * 08 calls its best part — with two changes it names explicitly:
 *
 *  1. `remaining_qty` is **derived** from `lot_movements`, recomputed under
 *     `lockForUpdate()`, never a mutable column that can drift.
 *  2. A POSITIVE adjustment creates an "adjustment lot" at the item's
 *     current average, closing the prior-art gap where found stock on a
 *     lot-tracked item could not be consumed by FEFO.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mariadb = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items');
            $table->unsignedSmallInteger('location_id');
            $table->string('lot_code', 64);
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('received_qty', 16, 5);
            // The cost this specific lot came in at — captured for
            // traceability even though COGS uses the moving average (08 §"Locked decisions").
            $table->decimal('cost_per_base', 19, 6)->default(0);
            $table->enum('status', ['active', 'consumed', 'expired'])->default('active');
            $table->string('source_type', 48)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'location_id', 'lot_code'], 'uq_lot_identity');
            // FEFO reads this: soonest expiry first, nulls last.
            $table->index(['item_id', 'location_id', 'status', 'expiry_date'], 'idx_lot_fefo');
            $table->foreign('location_id', 'fk_lot_location')->references('id')->on('locations');
        });

        // Append-only, like stock_movements: remaining_qty is Σ qty_delta.
        Schema::create('lot_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->cascadeOnDelete();
            $table->enum('reason', ['receive', 'release', 'adjustment', 'reversal', 'expiry']);
            $table->decimal('qty_delta', 16, 5);
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->string('source_type', 48)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('moved_at');
            $table->unsignedBigInteger('created_by');

            $table->index(['source_type', 'source_id'], 'idx_lot_movements_source');
            $table->foreign('stock_movement_id', 'fk_lot_movement_stock')->references('id')->on('stock_movements');
        });

        if ($mariadb) {
            DB::unprepared('ALTER TABLE lot_movements ADD CONSTRAINT chk_lot_movement_nonzero CHECK (qty_delta <> 0)');
            DB::unprepared('ALTER TABLE inventory_lots ADD CONSTRAINT chk_lot_received_positive CHECK (received_qty > 0)');

            // Append-only, for the same reason the stock ledger is.
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER bu_lot_movements BEFORE UPDATE ON lot_movements FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lot_movements is append-only; post an opposite movement instead';
                END
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER bd_lot_movements BEFORE DELETE ON lot_movements FOR EACH ROW
                BEGIN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lot_movements is append-only; movements are never deleted';
                END
                SQL);
        }
    }

    public function down(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS bu_lot_movements');
            DB::unprepared('DROP TRIGGER IF EXISTS bd_lot_movements');
        }

        Schema::dropIfExists('lot_movements');
        Schema::dropIfExists('inventory_lots');
    }
};
