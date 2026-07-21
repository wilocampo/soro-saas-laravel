<?php

namespace App\Domain\Documents\Import;

use App\Domain\Ledger\Exceptions\InvalidDraft;

/**
 * Minimal header-keyed CSV reader. No dependency: the import surface is a
 * handful of flat files, and `fgetcsv` handles quoting and embedded newlines
 * correctly on its own.
 */
class CsvReader
{
    /**
     * @param  list<string>  $required  header names that must be present
     * @return \Generator<int, array<string, string>> 1-indexed by DATA row
     */
    public function rows(string $path, array $required = []): \Generator
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new InvalidDraft("Cannot read the import file [{$path}].");
        }

        try {
            $header = fgetcsv($handle, escape: '');

            if ($header === false || $header === [null]) {
                throw new InvalidDraft('The import file is empty — the first line must be a header row.');
            }

            // Strip a UTF-8 BOM: Excel writes one and it silently corrupts
            // the first column name.
            $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);
            $header = array_map(fn ($name) => strtolower(trim((string) $name)), $header);

            $missing = array_diff($required, $header);
            if ($missing !== []) {
                throw new InvalidDraft('The import file is missing required column(s): '.implode(', ', $missing).'.');
            }

            $rowNumber = 0;

            while (($values = fgetcsv($handle, escape: '')) !== false) {
                if ($values === [null]) {   // blank line
                    continue;
                }

                $rowNumber++;
                $values = array_pad(array_slice($values, 0, count($header)), count($header), '');

                yield $rowNumber => array_map(
                    fn ($value) => trim((string) $value),
                    array_combine($header, $values)
                );
            }
        } finally {
            fclose($handle);
        }
    }
}
