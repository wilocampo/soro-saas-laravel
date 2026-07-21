<?php

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

/**
 * Typed rejection taxonomy (docs/specs/02, 05). One base class so callers
 * can catch "any ledger rule violation" and the tests can assert the exact
 * rule that fired.
 */
abstract class LedgerException extends RuntimeException {}
