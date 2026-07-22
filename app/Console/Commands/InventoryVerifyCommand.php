<?php

namespace App\Console\Commands;

use App\Domain\Inventory\StockLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The nightly stock integrity check (docs/specs/08 §4.4), the inventory
 * twin of `ledger:verify`.
 *
 * Three checks, in increasing order of what they'd mean if they failed:
 *  1. Cache vs movements — the derived on-hand still equals Σ qty_delta.
 *  2. Negative on-hand — impossible under the `block` policy, so its
 *     presence means something bypassed `StockLedger`.
 *  3. **Subledger-to-GL tie-out** — Σ(on-hand × avg cost) equals the
 *     inventory control account. This is the one that matters: drift here
 *     means the balance sheet is misstating inventory, and it is a sev-1
 *     exactly like a hash-chain break.
 */
class InventoryVerifyCommand extends Command
{
    protected $signature = 'inventory:verify {--tolerance=0 : centavos of GL drift to tolerate (default 0)}';

    protected $description = 'Verify the stock cache, on-hand sanity, and the inventory subledger-to-GL tie-out';

    public function handle(StockLedger $stock): int
    {
        $failures = 0;

        $failures += $this->verifyCache();
        $failures += $this->verifyNoNegativeStock();
        $failures += $this->verifyGeneralLedgerTie($stock);

        if ($failures > 0) {
            $this->error("inventory:verify FAILED — {$failures} problem(s) found.");

            return self::FAILURE;
        }

        $this->info('inventory:verify clean — stock cache, on-hand and the GL tie-out all check out.');

        return self::SUCCESS;
    }

    private function verifyCache(): int
    {
        $movements = DB::table('stock_movements')
            ->groupBy('item_id', 'location_id')
            ->selectRaw('item_id, location_id, SUM(qty_delta) AS qty')
            ->get()
            ->keyBy(fn ($row) => "{$row->item_id}:{$row->location_id}");

        $cached = DB::table('item_location_balances')
            ->get()
            ->keyBy(fn ($row) => "{$row->item_id}:{$row->location_id}");

        $failures = 0;

        foreach ($movements as $key => $row) {
            $have = $cached->has($key) ? $cached->get($key)->qty_on_hand : '0';

            if (bccomp((string) $row->qty, (string) $have, 5) !== 0) {
                $this->error("  cache drift at {$key}: movements say {$row->qty}, cache says {$have}");
                $failures++;
            }
        }

        // A cache row with no movements behind it is drift in the other
        // direction and would overstate on-hand.
        foreach ($cached as $key => $row) {
            if (! $movements->has($key) && bccomp((string) $row->qty_on_hand, '0', 5) !== 0) {
                $this->error("  cache row {$key} has {$row->qty_on_hand} on hand but no movements");
                $failures++;
            }
        }

        return $failures;
    }

    private function verifyNoNegativeStock(): int
    {
        $policy = DB::table('ledger_settings')->where('id', 1)->value('negative_stock_policy') ?? 'block';

        if ($policy !== 'block') {
            return 0;   // `warn` tenants accept negatives by choice (D17)
        }

        $negatives = DB::table('item_location_balances as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->where('b.qty_on_hand', '<', 0)
            ->get(['i.code', 'b.location_id', 'b.qty_on_hand']);

        foreach ($negatives as $row) {
            $this->error("  {$row->code} is negative at location {$row->location_id}: {$row->qty_on_hand}");
        }

        return $negatives->count();
    }

    /**
     * Σ(on-hand × avg cost) vs the inventory control account.
     *
     * A tolerance exists because moving-average rounding can legitimately
     * leave a few centavos between the perpetual valuation and the sum of
     * posted entries; it defaults to ZERO so drift is loud unless an
     * operator has consciously accepted a band.
     */
    private function verifyGeneralLedgerTie(StockLedger $stock): int
    {
        $accountId = DB::table('account_roles')->where('role', 'inventory')->value('account_id');

        if ($accountId === null) {
            $this->warn('  no `inventory` account role is mapped — skipping the GL tie-out.');

            return 0;
        }

        $control = (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $accountId)
            ->whereIn('je.status', ['posted', 'void'])
            ->selectRaw('COALESCE(SUM(jl.debit_centavos),0) - COALESCE(SUM(jl.credit_centavos),0) AS net')
            ->value('net');

        $valuation = $stock->valuationCentavos();
        $drift = $valuation - $control;
        $tolerance = (int) $this->option('tolerance');

        $this->line(sprintf(
            '  inventory: subledger %s vs GL %s (drift %s)',
            number_format($valuation / 100, 2),
            number_format($control / 100, 2),
            number_format($drift / 100, 2),
        ));

        if (abs($drift) > $tolerance) {
            $this->error('  the inventory subledger does not tie to the general ledger.');

            return 1;
        }

        return 0;
    }
}
