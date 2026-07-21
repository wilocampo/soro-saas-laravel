<?php

namespace App\Domain\Ledger\Exceptions;

/** A line references an inactive or non-postable account (docs/specs/02). */
class AccountNotPostable extends LedgerException {}
