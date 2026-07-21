<?php

namespace App\Domain\Ledger\Exceptions;

/** The entry date is on or before the posting lock date (docs/specs/02). */
class PostingLocked extends LedgerException {}
