<?php

namespace App\Domain\Ledger\Exceptions;

/** This entry cannot be reversed (docs/specs/02). */
class ReversalNotAllowed extends LedgerException {}
