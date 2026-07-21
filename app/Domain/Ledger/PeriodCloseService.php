<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exceptions\PeriodClosed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monthly close / reopen (docs/specs/01 §5, fixture S9). Closing flips the
 * period to `closed`, rolls balances forward, and advances
 * posting_lock_date — there is no closing *entry* (P&L reports are
 * period-scoped sums). Reopen is owner-only (spec 04) and always audited.
 */
class PeriodCloseService
{
    public function __construct(
        private readonly BalanceCache $balances,
        private readonly AuditLogger $audit,
    ) {}

    public function close(int $periodId, ?int $actorId = null, ?string $actorName = null): void
    {
        DB::transaction(function () use ($periodId, $actorId, $actorName): void {
            // Close takes the period FOR UPDATE; posting takes it FOR SHARE
            // (02 §6) — no entry can slip into a period as it closes.
            $period = DB::table('fiscal_periods')->where('id', $periodId)->lockForUpdate()->first();

            if ($period === null) {
                throw new PeriodClosed("Fiscal period [{$periodId}] does not exist.");
            }
            if ($period->status !== 'open') {
                throw new PeriodClosed("Period {$period->period_no} is already {$period->status}.");
            }

            // Periods close in order: an earlier open period would make the
            // roll-forward opening balances wrong.
            $earlierOpen = DB::table('fiscal_periods as fp')
                ->join('fiscal_years as fy', 'fy.id', '=', 'fp.fiscal_year_id')
                ->where('fp.status', 'open')
                ->where('fp.period_no', '<=', 12)
                ->where('fp.id', '<>', $periodId)
                ->where('fp.end_date', '<', $period->end_date)
                ->orderBy('fp.end_date')
                ->value('fp.period_no');

            if ($earlierOpen !== null) {
                throw new PeriodClosed("Period {$earlierOpen} is still open — periods must close in order.");
            }

            // Drafts would be orphaned behind the lock date; make the operator
            // resolve them rather than silently deleting accounting work.
            $drafts = DB::table('journal_entries')
                ->where('fiscal_period_id', $periodId)->where('status', 'draft')->count();
            if ($drafts > 0) {
                throw new PeriodClosed("Period {$period->period_no} still has {$drafts} draft entr(ies) — post or discard them first.");
            }

            $this->balances->rollForward();

            DB::table('fiscal_periods')->where('id', $periodId)->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $actorId,
            ]);

            // Advance the lock date, never retreat it. Period 13 (the
            // adjustment window) is exempt from the lock by design (01 §7).
            if ((int) $period->period_no !== 13) {
                $currentLock = DB::table('ledger_settings')->where('id', 1)->value('posting_lock_date');
                if ($currentLock === null || $period->end_date > $currentLock) {
                    DB::table('ledger_settings')->where('id', 1)->update([
                        'posting_lock_date' => $period->end_date,
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->audit->record(
                event: 'period.closed',
                auditableType: 'fiscal_period',
                auditableId: $periodId,
                before: ['status' => 'open'],
                after: ['status' => 'closed', 'posting_lock_date' => $period->end_date],
                actorId: $actorId,
                actorName: $actorName,
            );
        });
    }

    /**
     * Reopen a closed period. Owner-only at the policy layer (spec 04);
     * `locked` periods (audited/filed) are never reopened here.
     */
    public function reopen(int $periodId, ?int $actorId = null, ?string $actorName = null, string $reason = ''): void
    {
        DB::transaction(function () use ($periodId, $actorId, $actorName, $reason): void {
            $period = DB::table('fiscal_periods')->where('id', $periodId)->lockForUpdate()->first();

            if ($period === null || $period->status !== 'closed') {
                throw new PeriodClosed('Only a closed period can be reopened.');
            }

            $fyStatus = DB::table('fiscal_years')->where('id', $period->fiscal_year_id)->value('status');
            if ($fyStatus !== 'open') {
                throw new PeriodClosed('The fiscal year is closed — reopen the year first.');
            }

            DB::table('fiscal_periods')->where('id', $periodId)->update([
                'status' => 'open',
                'closed_at' => null,
                'closed_by' => null,
            ]);

            // Retreat the lock to the end of the newest still-closed period.
            $newLock = DB::table('fiscal_periods')
                ->where('status', '<>', 'open')->where('period_no', '<=', 12)
                ->max('end_date');

            DB::table('ledger_settings')->where('id', 1)->update([
                'posting_lock_date' => $newLock,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                event: 'period.reopened',
                auditableType: 'fiscal_period',
                auditableId: $periodId,
                before: ['status' => 'closed'],
                after: ['status' => 'open', 'posting_lock_date' => $newLock, 'reason' => $reason],
                actorId: $actorId,
                actorName: $actorName,
            );
        });
    }

    /** The period covering a date (periods 1–12; 13 is explicit-routing only). */
    public function periodForDate(CarbonImmutable $date): ?int
    {
        $id = DB::table('fiscal_periods')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->where('period_no', '<=', 12)
            ->orderBy('period_no')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
