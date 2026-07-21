<?php

namespace App\Domain\Ledger\Exceptions;

/** This entry cannot be voided (docs/specs/02). */
class VoidNotAllowed extends LedgerException {}
