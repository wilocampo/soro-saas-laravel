<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Ledger\Exceptions\InvalidDraft;
use Illuminate\Support\Facades\DB;

/** Everything a PostingRule needs to build a draft (docs/specs/02 §2). */
class PostingContext
{
    public function __construct(
        public readonly AccountingBasis $basis,
        public readonly AccountResolver $accounts,
        public readonly TaxResolver $tax,
        public readonly RoundingPolicy $rounding,
        public readonly bool $isVatRegistered = true,
    ) {}

    /** Build from the tenant's ledger_settings (the registered basis, D2). */
    public static function fromSettings(
        AccountResolver $accounts,
        TaxResolver $tax,
        RoundingPolicy $rounding,
    ): self {
        $settings = DB::table('ledger_settings')->where('id', 1)->first();

        return new self(
            AccountingBasis::from($settings->accounting_basis ?? 'accrual'),
            $accounts,
            $tax,
            $rounding,
            (bool) ($settings->is_vat_registered ?? true),
        );
    }

    /**
     * A NON-VAT registrant files 2551Q percentage tax and may not shift VAT
     * to anyone (spec 03 §5). A VAT amount on its document is a data error
     * we refuse loudly rather than post to an account it must not use.
     */
    public function assertMayShiftVat(int $vatCentavos, string $document): void
    {
        if ($vatCentavos !== 0 && ! $this->isVatRegistered) {
            throw new InvalidDraft(
                "{$document} carries {$vatCentavos} centavos of VAT but the tenant is registered NON-VAT "
                .'— a non-VAT registrant cannot shift VAT (spec 03 §5).'
            );
        }
    }
}
