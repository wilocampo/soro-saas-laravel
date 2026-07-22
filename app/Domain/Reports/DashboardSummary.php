<?php

namespace App\Domain\Reports;

use App\Domain\Documents\AgingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard-lite (Phase 3): cash on hand, what is overdue on both sides, a
 * P&L snapshot, and the compliance deadlines that are actually coming.
 *
 * Every figure is derived from the same services the reports use, so the
 * dashboard can never tell a different story from the statements.
 */
class DashboardSummary
{
    public function __construct(
        private readonly FinancialStatements $statements,
        private readonly AgingService $aging,
    ) {}

    /** @return array<string, mixed> */
    public function forToday(?CarbonImmutable $asOf = null): array
    {
        $asOf = $asOf ?? CarbonImmutable::now();   // app TZ is Asia/Manila (D18)
        $periodId = $this->currentPeriodId($asOf);

        $receivables = $this->aging->receivables($asOf);
        $payables = $this->aging->payables($asOf);

        return [
            'as_of' => $asOf->toDateString(),
            'cash' => $this->cashAccounts($periodId),
            'receivables' => $this->agingSnapshot($receivables),
            'payables' => $this->agingSnapshot($payables),
            'profit_and_loss' => $periodId === null ? null : $this->profitAndLoss($periodId),
            'deadlines' => $this->deadlines($asOf),
            'period' => $periodId === null ? null : $this->period($periodId),
        ];
    }

    /**
     * Cash and cash equivalents, per account. Read from the trial balance so
     * it agrees with the balance sheet to the centavo.
     *
     * @return array{accounts:list<array<string,mixed>>, total:int}
     */
    private function cashAccounts(?int $periodId): array
    {
        if ($periodId === null) {
            return ['accounts' => [], 'total' => 0];
        }

        $cashCodes = DB::table('account_roles')
            ->whereIn('role', ['cash'])
            ->pluck('account_id')
            ->all();

        // Everything under the 10xx cash block, plus whatever is mapped to
        // the cash role — a tenant may rename its accounts but the role map
        // is authoritative (01 §7).
        /** @var list<array<string, mixed>> $assets */
        $assets = $this->statements->balanceSheet($periodId)['sections']['assets']['rows'];

        $rows = array_values(array_filter(
            $assets,
            fn (array $row) => str_starts_with((string) $row['code'], '10')
                || in_array($row['account_id'], $cashCodes, true),
        ));

        return ['accounts' => $rows, 'total' => array_sum(array_column($rows, 'amount'))];
    }

    /**
     * @param  array{totals:array<string,int>, partners:list<array<string,mixed>>}  $aging
     * @return array<string, mixed>
     */
    private function agingSnapshot(array $aging): array
    {
        $totals = $aging['totals'];
        $overdue = $totals['total'] - $totals['current'];

        return [
            'total' => $totals['total'],
            'current' => $totals['current'],
            'overdue' => $overdue,
            'over_90' => $totals['over_90'],
            'buckets' => $totals,
            // The three worst, which is what an owner actually chases.
            'worst' => $this->worstOffenders($aging['partners']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $partners
     * @return list<array<string, mixed>>
     */
    private function worstOffenders(array $partners): array
    {
        usort(
            $partners,
            fn (array $a, array $b) => ($b['total'] - $b['current']) <=> ($a['total'] - $a['current']),
        );

        return array_slice($partners, 0, 3);
    }

    /** @return array<string, mixed> */
    private function profitAndLoss(int $periodId): array
    {
        $yearToDate = $this->statements->incomeStatement($periodId, yearToDate: true);
        $thisPeriod = $this->statements->incomeStatement($periodId, yearToDate: false);

        return [
            'year_to_date' => [
                'income' => $yearToDate['sections']['income']['total'],
                'expenses' => $yearToDate['sections']['expenses']['total'],
                'net_income' => $yearToDate['net_income'],
            ],
            'this_period' => [
                'income' => $thisPeriod['sections']['income']['total'],
                'expenses' => $thisPeriod['sections']['expenses']['total'],
                'net_income' => $thisPeriod['net_income'],
            ],
        ];
    }

    /**
     * The filing calendar (docs/specs/03 §7). Dates are statutory deadlines,
     * not reminders someone typed in — 2550Q is quarter-end + 25 days, the
     * monthly 2550M no longer exists (TRAIN, operative 2023).
     *
     * ⚠ The Phase-4 return generators are what actually file these; this is
     * a heads-up list, and the CPA sign-off in spec 06 still governs.
     *
     * @return list<array<string, mixed>>
     */
    private function deadlines(CarbonImmutable $asOf): array
    {
        $deadlines = [];

        // 2550Q — quarterly VAT, due 25 days after the quarter closes.
        $quarterEnd = $asOf->firstOfQuarter()->addMonths(3)->subDay();
        $deadlines[] = [
            'form' => '2550Q',
            'name' => 'Quarterly VAT return',
            'due' => $quarterEnd->addDays(25)->toDateString(),
            'covers' => $asOf->firstOfQuarter()->toDateString().' to '.$quarterEnd->toDateString(),
        ];

        // 1601EQ — quarterly expanded withholding remittance.
        $deadlines[] = [
            'form' => '1601EQ',
            'name' => 'Quarterly expanded withholding tax',
            'due' => $quarterEnd->addMonth()->lastOfMonth()->toDateString(),
            'covers' => $asOf->firstOfQuarter()->toDateString().' to '.$quarterEnd->toDateString(),
        ];

        // 0619E — monthly EWT remittance for the first two months of a quarter.
        if ($asOf->month % 3 !== 0) {
            $deadlines[] = [
                'form' => '0619E',
                'name' => 'Monthly expanded withholding remittance',
                'due' => $asOf->addMonth()->startOfMonth()->addDays(9)->toDateString(),
                'covers' => $asOf->startOfMonth()->toDateString().' to '.$asOf->endOfMonth()->toDateString(),
            ];
        }

        usort($deadlines, fn (array $a, array $b) => $a['due'] <=> $b['due']);

        foreach ($deadlines as $index => $deadline) {
            $deadlines[$index]['days_remaining'] = (int) $asOf->startOfDay()
                ->diffInDays(CarbonImmutable::parse($deadline['due'])->startOfDay(), false);
        }

        return $deadlines;
    }

    private function currentPeriodId(CarbonImmutable $asOf): ?int
    {
        $id = DB::table('fiscal_periods')
            ->whereDate('start_date', '<=', $asOf->toDateString())
            ->whereDate('end_date', '>=', $asOf->toDateString())
            ->where('period_no', '<=', 12)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array<string, mixed> */
    private function period(int $periodId): array
    {
        return (array) DB::table('fiscal_periods as fp')
            ->join('fiscal_years as fy', 'fy.id', '=', 'fp.fiscal_year_id')
            ->where('fp.id', $periodId)
            ->first(['fp.id', 'fp.period_no', 'fp.start_date', 'fp.end_date', 'fp.status', 'fy.year_label']);
    }
}
