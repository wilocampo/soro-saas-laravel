<?php

namespace App\Domain\Documents\Import;

/**
 * The outcome of one import run. Row errors are COLLECTED, not thrown: a
 * cutover file with three bad rows out of four hundred should tell the
 * operator which three, not abort on the first.
 */
class ImportResult
{
    /** @var list<array{row:int, message:string}> */
    public array $errors = [];

    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public function fail(int $row, string $message): void
    {
        $this->errors[] = ['row' => $row, 'message' => $message];
        $this->skipped++;
    }

    public function isClean(): bool
    {
        return $this->errors === [];
    }

    public function summary(): string
    {
        return "{$this->created} created, {$this->updated} updated, {$this->skipped} skipped, "
            .count($this->errors).' error(s)';
    }
}
