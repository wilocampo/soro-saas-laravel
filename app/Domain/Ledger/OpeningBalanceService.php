<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exceptions\InvalidDraft;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\JournalLineDraft;
use App\Domain\Ledger\Posting\SourceRef;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Go-live opening balances (docs/specs/01 §5, fixture S1): one posted,
 * immutable JE dated the day before go-live. **Opening Balance Equity**
 * absorbs the plug so balances can be entered account-by-account; an
 * accountant reconciles OBE into Retained Earnings afterwards.
 */
class OpeningBalanceService
{
    public function __construct(
        private readonly PostingService $posting,
    ) {}

    /**
     * @param  array<string, int>  $balancesByCode  natural (positive = the
     *                                              account's normal side) centavos
     */
    public function post(array $balancesByCode, CarbonImmutable $asOf, ?int $fiscalPeriodId = null): ?JournalEntry
    {
        if ($balancesByCode === []) {
            return null;
        }

        $obeAccountId = DB::table('ledger_settings')->where('id', 1)->value('opening_balance_equity_account_id');
        if ($obeAccountId === null) {
            throw new InvalidDraft('No Opening Balance Equity account is configured in ledger_settings.');
        }

        $accounts = DB::table('accounts')->whereIn('code', array_keys($balancesByCode))
            ->get(['id', 'code', 'normal_balance'])->keyBy('code');

        $lines = [];
        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($balancesByCode as $code => $centavos) {
            if ($centavos === 0) {
                continue;
            }

            $account = $accounts->get((string) $code);
            if ($account === null) {
                throw new InvalidDraft("Unknown account code [{$code}] in the opening balances.");
            }

            // A natural balance posts to the account's normal side; a
            // negative figure flips it.
            $onDebitSide = ($account->normal_balance === 'debit') === ($centavos > 0);
            $amount = abs($centavos);

            $lines[] = new JournalLineDraft(
                accountId: (int) $account->id,
                debitCentavos: $onDebitSide ? $amount : 0,
                creditCentavos: $onDebitSide ? 0 : $amount,
                memo: 'Opening balance',
            );

            $onDebitSide ? $debitTotal += $amount : $creditTotal += $amount;
        }

        // The plug: OBE takes whichever side makes the entry balance.
        $difference = $debitTotal - $creditTotal;
        if ($difference !== 0) {
            $lines[] = new JournalLineDraft(
                accountId: (int) $obeAccountId,
                debitCentavos: $difference < 0 ? abs($difference) : 0,
                creditCentavos: $difference > 0 ? $difference : 0,
                memo: 'Opening Balance Equity (plug)',
            );
        }

        return $this->posting->post(new JournalDraft(
            journalBook: 'opening_balance',
            entryDate: $asOf,
            memo: 'Opening balances as of '.$asOf->toDateString(),
            source: SourceRef::none(),
            idempotencyKey: 'opening_balance:'.$asOf->toDateString().':post:1',
            lines: $lines,
            fiscalPeriodId: $fiscalPeriodId,
        ));
    }
}
