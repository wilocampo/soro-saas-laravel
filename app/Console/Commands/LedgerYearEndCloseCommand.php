<?php

namespace App\Console\Commands;

use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\YearEndCloseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Year-end close (docs/specs/01 §5, fixture S8). CPA sign-off pending (06). */
class LedgerYearEndCloseCommand extends Command
{
    protected $signature = 'ledger:year-end-close {year? : fiscal_years.id or year_label (default: the current fiscal year)}';

    protected $description = 'Close nominal accounts into Income Summary then Retained Earnings, and close the fiscal year';

    public function handle(YearEndCloseService $close): int
    {
        $yearId = $this->resolveYearId();

        if ($yearId === null) {
            $this->error('No such fiscal year.');

            return self::FAILURE;
        }

        try {
            $entry = $close->close($yearId);
        } catch (LedgerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($entry === null
            ? 'Fiscal year closed — no nominal activity to close out.'
            : "Fiscal year closed with entry {$entry->entry_number}.");

        return self::SUCCESS;
    }

    private function resolveYearId(): ?int
    {
        $argument = $this->argument('year');

        if ($argument === null) {
            $id = DB::table('ledger_settings')->where('id', 1)->value('current_fiscal_year_id');

            return $id === null ? null : (int) $id;
        }

        $id = DB::table('fiscal_years')
            ->where('id', $argument)->orWhere('year_label', $argument)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
