<?php

namespace App\Domain\Reports;

use Illuminate\Support\Facades\DB;

/**
 * The journals: every entry in a book over a date range, with its lines
 * (docs/specs/03 §3).
 *
 * One service covers all six BIR books because they differ only by which
 * `journal_book` they select — General Journal, Sales, Purchase, Cash
 * Receipts, Cash Disbursements. The strict BIR columnar layouts and the
 * `.dat` exports are Phase 4; this is the readable view they will be built
 * from.
 *
 * Void entries are INCLUDED and shown as void. A journal that hides them
 * would be exactly the "suppression feature" the NIRC prohibits — the
 * reader must be able to see that something was reversed.
 */
class JournalReport
{
    /** journal_book → the book's conventional name and BIR series. */
    public const BOOKS = [
        'general' => ['name' => 'General Journal', 'series' => 'GJ'],
        'sales' => ['name' => 'Sales Journal', 'series' => 'SJ'],
        'purchase' => ['name' => 'Purchase Journal', 'series' => 'PJ'],
        'cash_receipts' => ['name' => 'Cash Receipts Book', 'series' => 'CRJ'],
        'cash_disbursements' => ['name' => 'Cash Disbursements Book', 'series' => 'CDJ'],
        'opening_balance' => ['name' => 'Opening Balances', 'series' => 'OB'],
        'year_end_close' => ['name' => 'Year-End Closing', 'series' => 'YEC'],
        'reversal' => ['name' => 'Reversals', 'series' => 'REV'],
    ];

    /**
     * @param  string|null  $book  null = every book, i.e. the full journal
     * @return array{
     *   book:?string, book_name:string, from:string, to:string,
     *   entries:list<array<string,mixed>>, total_debits:int, total_credits:int, balanced:bool
     * }
     */
    public function entries(string $from, string $to, ?string $book = null): array
    {
        $headers = DB::table('journal_entries')
            ->when($book !== null, fn ($q) => $q->where('journal_book', $book))
            ->whereIn('status', ['posted', 'void'])
            ->whereBetween('entry_date', [$from, $to])
            ->orderBy('entry_date')
            ->orderBy('entry_number')
            ->get([
                'id', 'entry_number', 'entry_date', 'journal_book', 'description', 'status',
                'source_type', 'source_id', 'reverses_entry_id', 'reversed_by_entry_id',
            ]);

        $lines = $this->linesFor($headers->pluck('id')->all());

        $entries = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($headers as $header) {
            $entryLines = $lines[$header->id] ?? [];

            $totalDebits += array_sum(array_column($entryLines, 'debit'));
            $totalCredits += array_sum(array_column($entryLines, 'credit'));

            $entries[] = [
                'id' => (int) $header->id,
                'entry_number' => $header->entry_number,
                'entry_date' => $header->entry_date,
                'journal_book' => $header->journal_book,
                'book_name' => self::BOOKS[$header->journal_book]['name'] ?? $header->journal_book,
                'memo' => $header->description,
                'status' => $header->status,
                'source_type' => $header->source_type,
                'source_id' => $header->source_id === null ? null : (int) $header->source_id,
                'is_reversal' => $header->reverses_entry_id !== null,
                'is_reversed' => $header->reversed_by_entry_id !== null,
                'lines' => $entryLines,
            ];
        }

        return [
            'book' => $book,
            'book_name' => $book === null ? 'All journals' : (self::BOOKS[$book]['name'] ?? $book),
            'from' => $from,
            'to' => $to,
            'entries' => $entries,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            // Every entry balances individually, so any range of them must
            // too. If this is false the ledger has been tampered with.
            'balanced' => $totalDebits === $totalCredits,
        ];
    }

    /**
     * @param  list<int|string>  $entryIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function linesFor(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        return DB::table('journal_lines as jl')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('jl.journal_entry_id', $entryIds)
            ->orderBy('jl.journal_entry_id')
            ->orderBy('jl.line_no')
            ->get([
                'jl.journal_entry_id', 'jl.line_no', 'jl.debit_centavos', 'jl.credit_centavos',
                'jl.memo', 'jl.tax_base_centavos', 'jl.atc_code', 'a.code', 'a.name',
            ])
            ->groupBy('journal_entry_id')
            ->map(fn ($group) => $group->map(fn ($line) => [
                'line_no' => (int) $line->line_no,
                'code' => $line->code,
                'name' => $line->name,
                'memo' => $line->memo,
                'debit' => (int) $line->debit_centavos,
                'credit' => (int) $line->credit_centavos,
                // Carried for the Phase-4 BIR books and returns.
                'tax_base' => $line->tax_base_centavos === null ? null : (int) $line->tax_base_centavos,
                'atc_code' => $line->atc_code,
            ])->all())
            ->all();
    }
}
