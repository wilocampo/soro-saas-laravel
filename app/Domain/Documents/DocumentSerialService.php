<?php

namespace App\Domain\Documents;

use App\Domain\Ledger\Exceptions\InvalidDraft;
use Illuminate\Support\Facades\DB;

/**
 * Customer-facing serial numbers (RMC 77-2024, docs/specs/03 §4).
 *
 * These differ from the internal journal-book numbers in one crucial way:
 * an invoice series is CONTINUOUS — it never resets at year end and it
 * carries on across a system migration — so it lives in `serial_sequences`
 * rather than the fiscal-year-scoped `document_sequences`, and its prefix
 * must NOT embed a year label.
 *
 * Drawn under `SELECT … FOR UPDATE` inside the caller's transaction, so a
 * rollback consumes no number and the series stays gapless (CLAUDE.md #6).
 */
class DocumentSerialService
{
    public function next(string $series): string
    {
        if (! DB::transactionLevel()) {
            throw new InvalidDraft("Serial [{$series}] must be drawn inside a transaction, or a rollback would leave a gap (01 §3).");
        }

        $branchId = $this->currentBranchId();

        $sequence = DB::table('serial_sequences')
            ->where('series', $series)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            throw new InvalidDraft("No serial sequence for series [{$series}] at branch [{$branchId}].");
        }

        DB::table('serial_sequences')->where('id', $sequence->id)
            ->update(['last_value' => DB::raw('last_value + 1')]);

        return $sequence->prefix.str_pad(
            (string) ($sequence->last_value + 1),
            $sequence->pad_width,
            '0',
            STR_PAD_LEFT
        );
    }

    private function currentBranchId(): int
    {
        $id = DB::table('branches')->where('is_main', true)->where('is_active', true)->value('id')
            ?? DB::table('branches')->where('is_active', true)->orderBy('id')->value('id');

        if ($id === null) {
            throw new InvalidDraft('No active branch — BIR serials are issued per registered branch (01 §7).');
        }

        return (int) $id;
    }
}
