<?php

namespace App\Domain\Ledger\Posting;

/**
 * The tenant's REGISTERED accounting basis (docs/specs/02 §2, D2). Posting
 * is basis-selective: the GL is physically in the registered basis, because
 * BIR examines the books themselves. Switching is a restatement event.
 */
enum AccountingBasis: string
{
    case Accrual = 'accrual';
    case Cash = 'cash';

    public function isCash(): bool
    {
        return $this === self::Cash;
    }
}
