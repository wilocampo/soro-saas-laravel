<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Ledger\Exceptions\LedgerException;

/**
 * Base for stock-ledger failures. Extends the ledger exception because the
 * two subsystems share a transaction and callers catch one type.
 */
class InventoryException extends LedgerException {}
