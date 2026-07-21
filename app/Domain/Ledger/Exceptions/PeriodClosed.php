<?php

namespace App\Domain\Ledger\Exceptions;

/** The fiscal period is not open for posting (docs/specs/02). */
class PeriodClosed extends LedgerException {}
