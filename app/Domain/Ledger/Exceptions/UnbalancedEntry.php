<?php

namespace App\Domain\Ledger\Exceptions;

/** Total debits do not equal total credits (docs/specs/02). */
class UnbalancedEntry extends LedgerException {}
