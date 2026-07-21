<?php

namespace App\Domain\Ledger\Posting;

/**
 * One draft line. Exactly one of debit/credit must be positive (01 §1.6).
 * Tax/party fields carry the BIR data now, wire the returns later (spec 03).
 */
final class JournalLineDraft
{
    public function __construct(
        public readonly int $accountId,
        public readonly int $debitCentavos = 0,
        public readonly int $creditCentavos = 0,
        public readonly ?string $memo = null,
        public readonly ?int $taxCodeId = null,
        public readonly ?int $taxBaseCentavos = null,
        public readonly ?string $atcCode = null,
        public readonly ?PartyRef $party = null,
        public readonly ?string $sourceLineRef = null,
    ) {}
}
