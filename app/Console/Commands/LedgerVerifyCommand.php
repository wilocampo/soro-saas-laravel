<?php

namespace App\Console\Commands;

use App\Domain\Ledger\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly reconciliation (docs/specs/01 §2.3): re-derive every posted
 * entry's balance from journal_lines, re-compute posting_hash and the
 * balance cache, and walk the audit-log hash chain. Any failure is a
 * sev-1 signal (tampering, drift, or replica anomaly), not a log line.
 */
class LedgerVerifyCommand extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Verify entry balance, posting hashes, the balance cache, and the audit hash chain';

    public function handle(): int
    {
        $failures = 0;

        $failures += $this->verifyEntries();
        $failures += $this->verifyBalanceCache();
        $failures += $this->verifyAuditChain();

        if ($failures > 0) {
            $this->error("ledger:verify FAILED with {$failures} finding(s).");

            return self::FAILURE;
        }

        $this->info('ledger:verify clean — balances, hashes, and audit chain all check out.');

        return self::SUCCESS;
    }

    /** Σdebit = Σcredit per posted entry, totals match, posting_hash re-derives. */
    protected function verifyEntries(): int
    {
        $failures = 0;

        DB::table('journal_entries')->whereIn('status', ['posted', 'void'])
            ->orderBy('id')
            ->chunk(200, function ($entries) use (&$failures): void {
                foreach ($entries as $entry) {
                    $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)
                        ->orderBy('line_no')
                        ->get(['line_no', 'account_id', 'debit_centavos', 'credit_centavos']);

                    $sumD = (int) $lines->sum('debit_centavos');
                    $sumC = (int) $lines->sum('credit_centavos');

                    if ($lines->count() < 2 || $sumD === 0 || $sumD !== $sumC) {
                        $this->error("Entry {$entry->entry_number}: unbalanced ({$sumD} vs {$sumC}, {$lines->count()} lines).");
                        $failures++;
                    }

                    if ($sumD !== (int) $entry->total_debit_centavos || $sumC !== (int) $entry->total_credit_centavos) {
                        $this->error("Entry {$entry->entry_number}: header totals do not match lines.");
                        $failures++;
                    }

                    $hash = hash('sha256', json_encode(
                        $lines->map(fn ($l) => [(int) $l->line_no, (int) $l->account_id, (int) $l->debit_centavos, (int) $l->credit_centavos])
                    ));
                    if ($hash !== $entry->posting_hash) {
                        $this->error("Entry {$entry->entry_number}: posting_hash mismatch — lines were altered.");
                        $failures++;
                    }
                }
            });

        return $failures;
    }

    /** Cache movement columns must equal a fresh recompute from journal_lines. */
    protected function verifyBalanceCache(): int
    {
        $failures = 0;

        $fresh = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', ['posted', 'void'])
            ->select('jl.account_id', 'jl.fiscal_period_id')
            ->selectRaw('SUM(jl.debit_centavos) AS d, SUM(jl.credit_centavos) AS c')
            ->groupBy('jl.account_id', 'jl.fiscal_period_id')
            ->get()
            ->keyBy(fn ($r) => $r->account_id.':'.$r->fiscal_period_id);

        $cached = DB::table('account_period_balances')->get()
            ->keyBy(fn ($r) => $r->account_id.':'.$r->fiscal_period_id);

        foreach ($fresh as $key => $row) {
            $cache = $cached->get($key);
            if ($cache === null || (int) $cache->period_debits !== (int) $row->d || (int) $cache->period_credits !== (int) $row->c) {
                $this->error("Balance cache drift on account:period {$key}.");
                $failures++;
            }
        }
        foreach ($cached as $key => $row) {
            if (! $fresh->has($key) && ((int) $row->period_debits !== 0 || (int) $row->period_credits !== 0)) {
                $this->error("Balance cache has phantom movement on account:period {$key}.");
                $failures++;
            }
        }

        return $failures;
    }

    /** Walk the audit chain: each row_hash must re-derive from prev + payload. */
    protected function verifyAuditChain(): int
    {
        $failures = 0;
        $prev = null;

        DB::table('audit_log')->orderBy('id')->chunk(500, function ($rows) use (&$failures, &$prev): void {
            foreach ($rows as $row) {
                if ($row->prev_hash !== $prev) {
                    $this->error("Audit row {$row->id}: chain break (prev_hash mismatch).");
                    $failures++;
                }

                $payload = [
                    'occurred_at' => $row->occurred_at,
                    'actor_user_id' => $row->actor_user_id === null ? null : (int) $row->actor_user_id,
                    'actor_name' => $row->actor_name,
                    'event' => $row->event,
                    'auditable_type' => $row->auditable_type,
                    'auditable_id' => $row->auditable_id === null ? null : (int) $row->auditable_id,
                    'document_number' => $row->document_number,
                    'before' => $row->before_json === null ? null : json_decode($row->before_json, true),
                    'after' => $row->after_json === null ? null : json_decode($row->after_json, true),
                    'context' => $row->context_json === null ? null : json_decode($row->context_json, true),
                ];

                if (AuditLogger::hash($row->prev_hash, $payload) !== $row->row_hash) {
                    $this->error("Audit row {$row->id}: row_hash does not re-derive — payload was altered.");
                    $failures++;
                }

                $prev = $row->row_hash;
            }
        });

        return $failures;
    }
}
