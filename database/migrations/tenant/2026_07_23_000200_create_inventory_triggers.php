<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock-ledger immutability (docs/specs/08 §"Design philosophy" item 1).
 *
 * `stock_movements` is append-only for exactly the reason `journal_lines`
 * is: quantity history that can be edited is not history. A correction is an
 * opposite movement. DB safety net only — `StockLedger` is the primary
 * enforcer, and on sqlite (the fast suite) these tests skip and run on the
 * CI mariadb job instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        // S1 — an inventory movement must reference an inventory item at an
        // active location. Catching this here means no code path can slip a
        // service item into the stock ledger.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bi_stock_movements BEFORE INSERT ON stock_movements FOR EACH ROW
            BEGIN
              IF (SELECT COUNT(*) FROM items WHERE id = NEW.item_id AND item_type = 'inventory') = 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'only inventory-type items carry stock movements';
              END IF;
              IF (SELECT COUNT(*) FROM locations WHERE id = NEW.location_id AND is_active = 1) = 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock location is not active';
              END IF;
            END
            SQL);

        // S2/S3 — frozen once written. No UPDATE, no DELETE, ever.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bu_stock_movements BEFORE UPDATE ON stock_movements FOR EACH ROW
            BEGIN
              SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only; post an opposite movement instead';
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bd_stock_movements BEFORE DELETE ON stock_movements FOR EACH ROW
            BEGIN
              SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only; movements are never deleted';
            END
            SQL);
    }

    public function down(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['bi_stock_movements', 'bu_stock_movements', 'bd_stock_movements'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
