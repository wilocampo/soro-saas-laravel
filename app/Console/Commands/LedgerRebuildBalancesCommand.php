<?php

namespace App\Console\Commands;

use App\Domain\Ledger\BalanceCache;
use Illuminate\Console\Command;

/**
 * Rebuild account_period_balances from journal_lines (docs/specs/01 §4) —
 * proves the balance table is a cache, never truth.
 */
class LedgerRebuildBalancesCommand extends Command
{
    protected $signature = 'ledger:rebuild-balances';

    protected $description = 'Rebuild the derived balance cache from journal_lines and roll forward';

    public function handle(BalanceCache $balances): int
    {
        $balances->rebuild();

        $this->info('account_period_balances rebuilt from journal_lines.');

        return self::SUCCESS;
    }
}
