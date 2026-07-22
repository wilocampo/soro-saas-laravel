<?php

namespace App\Console\Commands;

use App\Domain\Inventory\StockLedger;
use Illuminate\Console\Command;

/**
 * Rebuild `item_location_balances` from `stock_movements` (docs/specs/08 §1).
 *
 * Its existence is the proof that on-hand is a CACHE and not the truth: if
 * this command can always reproduce it, nothing else needs to be trusted to
 * maintain it correctly.
 */
class InventoryRebuildBalancesCommand extends Command
{
    protected $signature = 'inventory:rebuild-balances';

    protected $description = 'Rebuild the on-hand cache from the append-only stock movements';

    public function handle(StockLedger $stock): int
    {
        $rows = $stock->rebuildBalances();

        $this->info("Rebuilt {$rows} item/location balance row(s) from stock_movements.");
        $this->line('Run `inventory:verify` to confirm the subledger still ties to the GL.');

        return self::SUCCESS;
    }
}
