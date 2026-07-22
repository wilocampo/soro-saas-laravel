<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory documents (docs/specs/08 §1 "Documents", §3).
 *
 * A goods receipt is a document like any other: it produces stock movements
 * AND a journal entry, and it is cancelled rather than deleted. Receiving
 * against a purchase order arrives with POs in v2, so lines carry a
 * `po_line_ref` placeholder now to avoid a later migration on a live table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mariadb = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->nullable()->unique();
            $table->foreignId('partner_id')->nullable()->constrained('partners');  // null = found/opening stock
            $table->unsignedSmallInteger('location_id');
            $table->date('received_date');
            $table->string('vendor_reference', 64)->nullable();   // the supplier's DR/invoice no.
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            // Total goods value in centavos — what hits Inventory.
            $table->unsignedBigInteger('total_cost_centavos')->default(0);
            // D15: a receipt credits GRNI until the vendor's bill arrives and
            // clears it to A/P. `vendor_bill_id` records that match.
            $table->unsignedBigInteger('vendor_bill_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('memo', 500)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'status']);
            $table->index('received_date');
            $table->foreign('location_id', 'fk_receipt_location')->references('id')->on('locations');
            $table->foreign('vendor_bill_id', 'fk_receipt_bill')->references('id')->on('vendor_bills');
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items');
            // What the operator typed, and what it converts to. Both are kept
            // so a receipt can be read back in the units it was entered in.
            $table->decimal('qty_entered', 16, 5);
            $table->unsignedSmallInteger('entered_uom_id');
            $table->decimal('conversion_factor', 16, 5)->default(1);
            $table->decimal('qty_stock', 16, 5);              // qty_entered × factor
            $table->decimal('unit_cost', 19, 6);              // per STOCK unit
            $table->unsignedBigInteger('line_cost_centavos');
            $table->string('lot_code', 64)->nullable();
            $table->date('expiry_date')->nullable();
            $table->unsignedBigInteger('po_line_ref')->nullable();   // v2
            $table->string('variance_note', 255)->nullable();        // price-deviation flag at encode time

            $table->unique(['goods_receipt_id', 'line_no'], 'uq_receipt_line');
            $table->foreign('entered_uom_id', 'fk_receipt_line_uom')->references('id')->on('uoms');
        });

        // A sales-invoice line can now reference an item, which is what makes
        // perpetual COGS possible (08 §2). `cogs_centavos` is captured at
        // ISSUE time so a cash-basis tenant — which recognises at collection,
        // possibly months later and at a different average cost — books the
        // cost that actually applied to the goods it shipped.
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->after('description')->constrained('items');
            $table->unsignedSmallInteger('location_id')->nullable()->after('item_id');
            $table->unsignedBigInteger('cogs_centavos')->default(0)->after('vat_centavos');
            $table->foreign('location_id', 'fk_invoice_line_location')->references('id')->on('locations');
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->index('vendor_bill_id', 'idx_receipt_bill');
        });

        if ($mariadb) {
            DB::unprepared('ALTER TABLE goods_receipt_lines ADD CONSTRAINT chk_receipt_qty_positive CHECK (qty_stock > 0)');
            DB::unprepared('ALTER TABLE goods_receipt_lines ADD CONSTRAINT chk_receipt_factor_positive CHECK (conversion_factor > 0)');
        }
    }

    public function down(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->dropForeign('fk_invoice_line_location');
            $table->dropForeign(['item_id']);
            $table->dropColumn(['item_id', 'location_id', 'cogs_centavos']);
        });

        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
