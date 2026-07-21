<?php

namespace App\Domain\Ledger\Posting;

use Illuminate\Support\Facades\DB;

/** Everything a PostingRule needs to build a draft (docs/specs/02 §2). */
class PostingContext
{
    public function __construct(
        public readonly AccountingBasis $basis,
        public readonly AccountResolver $accounts,
        public readonly TaxResolver $tax,
        public readonly RoundingPolicy $rounding,
    ) {}

    /** Build from the tenant's ledger_settings (the registered basis, D2). */
    public static function fromSettings(
        AccountResolver $accounts,
        TaxResolver $tax,
        RoundingPolicy $rounding,
    ): self {
        $basis = DB::table('ledger_settings')->where('id', 1)->value('accounting_basis') ?? 'accrual';

        return new self(AccountingBasis::from($basis), $accounts, $tax, $rounding);
    }
}
