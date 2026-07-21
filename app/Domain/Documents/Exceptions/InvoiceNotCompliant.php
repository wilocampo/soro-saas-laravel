<?php

namespace App\Domain\Documents\Exceptions;

use App\Domain\Ledger\Exceptions\LedgerException;

/** The invoice is missing a field the BIR treats as input-tax-fatal (spec 03 §4). */
class InvoiceNotCompliant extends LedgerException {}
