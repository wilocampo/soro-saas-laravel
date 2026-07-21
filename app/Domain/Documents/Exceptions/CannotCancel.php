<?php

namespace App\Domain\Documents\Exceptions;

use App\Domain\Ledger\Exceptions\LedgerException;

/** The document is past the point where cancellation is lawful (spec 03 §4). */
class CannotCancel extends LedgerException {}
