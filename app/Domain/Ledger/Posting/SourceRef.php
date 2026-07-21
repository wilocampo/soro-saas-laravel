<?php

namespace App\Domain\Ledger\Posting;

/** Polymorphic reference to the originating document (docs/specs/02 §1). */
final class SourceRef
{
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?int $id = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
