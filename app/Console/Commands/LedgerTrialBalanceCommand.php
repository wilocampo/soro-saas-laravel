<?php

namespace App\Console\Commands;

use App\Domain\Ledger\TrialBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Trial balance as of a fiscal period (docs/specs/01 §4). */
class LedgerTrialBalanceCommand extends Command
{
    protected $signature = 'ledger:trial-balance {--period= : fiscal_periods.id (default: the period covering today)}';

    protected $description = 'Print the trial balance as of a fiscal period and assert it ties';

    public function handle(TrialBalanceService $trialBalance): int
    {
        $periodId = $this->option('period') ?? DB::table('fiscal_periods')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->where('period_no', '<=', 12)
            ->value('id');

        if ($periodId === null) {
            $this->error('No fiscal period found.');

            return self::FAILURE;
        }

        $result = $trialBalance->asOfPeriod((int) $periodId);

        $this->table(
            ['Code', 'Account', 'Debit', 'Credit'],
            array_map(fn (array $row) => [
                $row['code'],
                $row['name'],
                $row['debit'] === 0 ? '' : number_format($row['debit'] / 100, 2),
                $row['credit'] === 0 ? '' : number_format($row['credit'] / 100, 2),
            ], $result['rows']),
        );

        $this->line(sprintf(
            'Totals: debit %s / credit %s',
            number_format($result['total_debit'] / 100, 2),
            number_format($result['total_credit'] / 100, 2),
        ));

        if (! $result['balanced']) {
            $this->error('TRIAL BALANCE DOES NOT TIE — run ledger:verify.');

            return self::FAILURE;
        }

        $this->info('Trial balance ties.');

        return self::SUCCESS;
    }
}
