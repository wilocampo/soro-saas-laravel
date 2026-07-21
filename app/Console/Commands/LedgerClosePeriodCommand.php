<?php

namespace App\Console\Commands;

use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\PeriodCloseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Month close / reopen (docs/specs/01 §5, fixture S9). Reopen is
 * owner-only in the UI (spec 04) and always writes an audit row.
 */
class LedgerClosePeriodCommand extends Command
{
    protected $signature = 'ledger:close-period
        {period : fiscal_periods.id, or the period number in the current fiscal year}
        {--reopen : Reopen a closed period instead}
        {--reason= : Reason (recorded in the audit log on reopen)}';

    protected $description = 'Close (or reopen) a fiscal period: roll balances forward and move the posting lock date';

    public function handle(PeriodCloseService $periods): int
    {
        $periodId = $this->resolvePeriodId((string) $this->argument('period'));

        if ($periodId === null) {
            $this->error('No such fiscal period.');

            return self::FAILURE;
        }

        try {
            if ($this->option('reopen')) {
                $periods->reopen($periodId, reason: (string) $this->option('reason'));
                $this->info("Period [{$periodId}] reopened.");
            } else {
                $periods->close($periodId);
                $lock = DB::table('ledger_settings')->where('id', 1)->value('posting_lock_date');
                $this->info("Period [{$periodId}] closed. Posting lock date is now {$lock}.");
            }
        } catch (LedgerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolvePeriodId(string $argument): ?int
    {
        $currentYear = DB::table('ledger_settings')->where('id', 1)->value('current_fiscal_year_id');

        // A small number is ambiguous: prefer period_no within the current FY.
        $byNumber = DB::table('fiscal_periods')
            ->where('fiscal_year_id', $currentYear)
            ->where('period_no', $argument)
            ->value('id');

        return $byNumber !== null
            ? (int) $byNumber
            : (DB::table('fiscal_periods')->where('id', $argument)->value('id') !== null ? (int) $argument : null);
    }
}
