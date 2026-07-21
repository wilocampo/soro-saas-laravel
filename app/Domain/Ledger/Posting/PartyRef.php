<?php

namespace App\Domain\Ledger\Posting;

/** Customer/vendor line dimension (docs/specs/02 §1). */
final class PartyRef
{
    public function __construct(
        public readonly string $type,   // 'customer'|'vendor'
        public readonly int $id,
    ) {}
}
