<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory core (docs/specs/08 §1).
 *
 * The stock ledger mirrors the journal exactly: `stock_movements` is
 * APPEND-ONLY and the only source of truth for quantity, on-hand is derived
 * and cached, and every money effect posts through `PostingService`.
 *
 * Quantities are DECIMAL(16,5) and average cost DECIMAL(19,6) — subledger
 * precision is allowed (01 §0) — but only rounded CENTAVOS ever post to the
 * journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mariadb = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('uoms', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('name', 48);
            $table->string('symbol', 16)->unique();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 16)->unique();
            $table->string('name', 128);
            $table->enum('type', ['warehouse', 'store'])->default('warehouse');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();
            // Only `inventory` items carry stock and post COGS; the others
            // exist so an invoice line can reference a catalogue entry.
            $table->enum('item_type', ['inventory', 'service', 'non_inventory'])->default('inventory');
            $table->unsignedSmallInteger('stock_uom_id');
            $table->unsignedSmallInteger('purchase_uom_id')->nullable();
            $table->decimal('purchase_to_stock_factor', 16, 5)->default(1);
            $table->enum('tracking', ['none', 'lot'])->default('none');
            $table->boolean('track_expiry')->default(false);
            $table->boolean('require_expiry')->default(false);
            $table->decimal('reorder_point', 16, 5)->default(0);
            // Per-item GL bindings: the posting rules read these, so two
            // items can capitalise to different inventory accounts.
            $table->unsignedBigInteger('inventory_account_id')->nullable();
            $table->unsignedBigInteger('income_account_id')->nullable();
            $table->unsignedBigInteger('cogs_account_id')->nullable();
            $table->unsignedBigInteger('adjustment_account_id')->nullable();
            // Moving weighted average is the single costing truth; last_cost
            // is informational only (08 §2 — fixes the ninetails bifurcation
            // where last cost drove valuation).
            $table->decimal('avg_cost', 19, 6)->default(0);
            $table->decimal('last_cost', 19, 6)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['item_type', 'is_active']);
            $table->foreign('stock_uom_id', 'fk_items_stock_uom')->references('id')->on('uoms');
            $table->foreign('purchase_uom_id', 'fk_items_purchase_uom')->references('id')->on('uoms');
            $table->foreign('inventory_account_id', 'fk_items_inventory_acct')->references('id')->on('accounts');
            $table->foreign('income_account_id', 'fk_items_income_acct')->references('id')->on('accounts');
            $table->foreign('cogs_account_id', 'fk_items_cogs_acct')->references('id')->on('accounts');
            $table->foreign('adjustment_account_id', 'fk_items_adjustment_acct')->references('id')->on('accounts');
        });

        Schema::create('item_uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->unsignedSmallInteger('from_uom_id');
            $table->unsignedSmallInteger('to_uom_id');
            $table->decimal('factor', 15, 10);

            $table->unique(['item_id', 'from_uom_id', 'to_uom_id'], 'uq_item_conversion');
            $table->foreign('from_uom_id', 'fk_conv_from_uom')->references('id')->on('uoms');
            $table->foreign('to_uom_id', 'fk_conv_to_uom')->references('id')->on('uoms');
        });

        Schema::create('item_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('barcode', 64)->unique();
            $table->enum('packaging_level', ['EACH', 'PACK', 'CASE'])->default('EACH');
            $table->unsignedSmallInteger('uom_id');

            $table->foreign('uom_id', 'fk_barcode_uom')->references('id')->on('uoms');
        });

        Schema::create('item_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners');
            $table->string('vendor_sku', 64)->nullable();
            $table->string('vendor_item_name')->nullable();
            $table->unsignedSmallInteger('pack_uom_id')->nullable();
            $table->decimal('default_conversion_factor', 16, 5)->nullable();
            $table->decimal('last_price', 19, 6)->nullable();
            $table->boolean('preferred')->default(false);

            $table->unique(['item_id', 'partner_id'], 'uq_item_vendor');
            $table->foreign('pack_uom_id', 'fk_item_vendor_uom')->references('id')->on('uoms');
        });

        // THE stock ledger. Append-only, exactly like journal_lines: a
        // correction is an opposite movement, never an edit or a delete.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items');
            $table->unsignedSmallInteger('location_id');
            $table->enum('movement_type', [
                'receive', 'sale', 'adjust_in', 'adjust_out',
                'transfer_in', 'transfer_out', 'count_adjust', 'reversal',
            ]);
            $table->decimal('qty_delta', 16, 5);              // signed
            $table->decimal('unit_cost', 19, 6)->default(0);  // cost context at movement time
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->string('source_type', 48)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamp('moved_at');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['item_id', 'location_id', 'moved_at'], 'idx_movements_item_location');
            $table->index(['source_type', 'source_id'], 'idx_movements_source');
            $table->foreign('location_id', 'fk_movements_location')->references('id')->on('locations');
        });

        // Derived cache — rebuildable, never the truth (08 §1).
        Schema::create('item_location_balances', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id');
            $table->unsignedSmallInteger('location_id');
            $table->decimal('qty_on_hand', 16, 5)->default(0);
            $table->timestamp('rebuilt_at')->nullable();

            $table->primary(['item_id', 'location_id']);
            $table->foreign('item_id', 'fk_ilb_item')->references('id')->on('items');
            $table->foreign('location_id', 'fk_ilb_location')->references('id')->on('locations');
        });

        Schema::table('ledger_settings', function (Blueprint $table) {
            // Goods Received Not Invoiced — the clearing account a receipt
            // credits until the vendor's bill arrives (D15).
            $table->foreignId('grni_account_id')->nullable()->after('rounding_account_id')->constrained('accounts');
            // D17: a sale that would drive stock negative is blocked by
            // default. BIR-clean books favour refusing over warning.
            $table->enum('negative_stock_policy', ['block', 'warn'])->default('block')->after('grni_account_id');
        });

        if ($mariadb) {
            // A movement of zero quantity is meaningless and would let a
            // caller "record" something that changes nothing.
            DB::unprepared('ALTER TABLE stock_movements ADD CONSTRAINT chk_movement_nonzero CHECK (qty_delta <> 0)');
            DB::unprepared('ALTER TABLE items ADD CONSTRAINT chk_item_factor_positive CHECK (purchase_to_stock_factor > 0)');
        }
    }

    public function down(): void
    {
        Schema::table('ledger_settings', function (Blueprint $table) {
            $table->dropForeign(['grni_account_id']);
            $table->dropColumn(['grni_account_id', 'negative_stock_policy']);
        });

        foreach ([
            'item_location_balances', 'stock_movements', 'item_vendors',
            'item_barcodes', 'item_uom_conversions', 'items', 'locations', 'uoms',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
