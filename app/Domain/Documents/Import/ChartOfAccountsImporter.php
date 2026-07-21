<?php

namespace App\Domain\Documents\Import;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chart of accounts from CSV (docs/specs/01 §1.2).
 *
 * Header: code,name,type,normal_balance,parent_code,is_contra,is_postable,
 *         bir_tax_type,bir_atc_code,bir_fs_line
 *
 * Two rules the import must not break:
 *  - A parent is a ROLL-UP: giving an account a child clears the parent's
 *    `is_postable`, because posting to both a parent and its children makes
 *    the hierarchy lie.
 *  - An account that already carries journal lines cannot change its type or
 *    normal balance — that would silently restate posted history.
 */
class ChartOfAccountsImporter
{
    public function __construct(private readonly CsvReader $reader) {}

    public function import(string $path): ImportResult
    {
        $result = new ImportResult;
        $types = DB::table('account_types')->pluck('id', 'code');

        // Two passes: every row exists before any parent link is resolved,
        // so a child may appear above its parent in the file.
        $rows = iterator_to_array($this->reader->rows($path, ['code', 'name', 'type']));

        foreach ($rows as $number => $row) {
            try {
                $this->upsert($row, $types) ? $result->created++ : $result->updated++;
            } catch (\InvalidArgumentException $e) {
                $result->fail($number, $e->getMessage());
            }
        }

        foreach ($rows as $number => $row) {
            $parentCode = $row['parent_code'] ?? '';

            if ($parentCode === '') {
                continue;
            }

            try {
                $this->linkParent((string) $row['code'], $parentCode);
            } catch (\InvalidArgumentException $e) {
                $result->fail($number, $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $row
     * @param  Collection<string, int>  $types
     * @return bool true when the account was created, false when updated
     */
    private function upsert(array $row, Collection $types): bool
    {
        $code = $row['code'];

        if ($code === '' || $row['name'] === '') {
            throw new \InvalidArgumentException('code and name are required.');
        }

        $typeId = $types->get(strtolower($row['type']));
        if ($typeId === null) {
            throw new \InvalidArgumentException("type [{$row['type']}] is not one of: ".$types->keys()->implode(', ').'.');
        }

        $normal = strtolower($row['normal_balance'] ?? '')
            ?: (string) DB::table('account_types')->where('id', $typeId)->value('normal_balance');

        if (! in_array($normal, ['debit', 'credit'], true)) {
            throw new \InvalidArgumentException("normal_balance [{$normal}] must be debit or credit.");
        }

        $existing = DB::table('accounts')->where('code', $code)->first();

        if ($existing !== null) {
            $posted = DB::table('journal_lines')->where('account_id', $existing->id)->exists();

            if ($posted && ((int) $existing->account_type_id !== (int) $typeId || $existing->normal_balance !== $normal)) {
                throw new \InvalidArgumentException(
                    "account [{$code}] already carries journal lines — its type and normal balance are frozen."
                );
            }

            DB::table('accounts')->where('id', $existing->id)->update([
                'name' => $row['name'],
                'account_type_id' => $typeId,
                'normal_balance' => $normal,
                'is_contra' => $this->bool($row['is_contra'] ?? ''),
                'bir_tax_type' => ($row['bir_tax_type'] ?? '') ?: null,
                'bir_atc_code' => ($row['bir_atc_code'] ?? '') ?: null,
                'bir_fs_line' => ($row['bir_fs_line'] ?? '') ?: null,
                'updated_at' => now(),
            ]);

            return false;
        }

        DB::table('accounts')->insert([
            'code' => $code,
            'name' => $row['name'],
            'account_type_id' => $typeId,
            'normal_balance' => $normal,
            'is_contra' => $this->bool($row['is_contra'] ?? ''),
            'is_postable' => ($row['is_postable'] ?? '') === '' ? true : $this->bool($row['is_postable']),
            'is_active' => true,
            'is_system' => false,
            'sort_order' => 0,
            'bir_tax_type' => ($row['bir_tax_type'] ?? '') ?: null,
            'bir_atc_code' => ($row['bir_atc_code'] ?? '') ?: null,
            'bir_fs_line' => ($row['bir_fs_line'] ?? '') ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function linkParent(string $code, string $parentCode): void
    {
        if ($code === $parentCode) {
            // The DB cannot express this (MariaDB rejects a CHECK on an
            // AUTO_INCREMENT column — 01 §1.2), so the app enforces it.
            throw new \InvalidArgumentException("account [{$code}] cannot be its own parent.");
        }

        $parent = DB::table('accounts')->where('code', $parentCode)->first();

        if ($parent === null) {
            throw new \InvalidArgumentException("parent_code [{$parentCode}] does not exist.");
        }

        if (DB::table('journal_lines')->where('account_id', $parent->id)->exists()) {
            throw new \InvalidArgumentException(
                "account [{$parentCode}] already carries journal lines and cannot become a roll-up parent."
            );
        }

        DB::table('accounts')->where('code', $code)->update(['parent_id' => $parent->id, 'updated_at' => now()]);
        // A parent is a roll-up, never a posting target.
        DB::table('accounts')->where('id', $parent->id)->update(['is_postable' => false, 'updated_at' => now()]);
    }

    private function bool(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'y', 'yes', 'true', 't'], true);
    }
}
