<?php

namespace App\Domain\Ledger\Exceptions;

/** Posted entries are immutable - corrections are reversing entries (docs/specs/02). */
class ImmutableEntry extends LedgerException {}
