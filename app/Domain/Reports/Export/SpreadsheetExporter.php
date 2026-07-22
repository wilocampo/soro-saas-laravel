<?php

namespace App\Domain\Reports\Export;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * XLSX and CSV export (docs/specs/09 — openspout, streaming, flat memory
 * regardless of size, which matters because a year's general ledger is not
 * small).
 *
 * Both formats carry the mandatory BIR header and footer block (RMC 5-2021
 * Annex B item 4) — a book exported without it is not a compliant book.
 * Money columns are written as real NUMBERS in XLSX so the reader can sum
 * them; CSV gets the formatted text, since a `.csv` is read as text.
 */
class SpreadsheetExporter
{
    public function xlsx(ReportSheet $sheet, string $path): string
    {
        return $this->write($sheet, $path, new XlsxWriter, numeric: true);
    }

    public function csv(ReportSheet $sheet, string $path): string
    {
        return $this->write($sheet, $path, new CsvWriter, numeric: false);
    }

    private function write(ReportSheet $sheet, string $path, WriterInterface $writer, bool $numeric): string
    {
        // openspout 5's Style is immutable and configured by constructor;
        // styling is applied per CELL, not per row.
        $bold = new Style(fontBold: true);

        $writer->openToFile($path);

        try {
            foreach ($sheet->headerLines() as $line) {
                $writer->addRow(new Row([Cell::fromValue($line, $bold)]));
            }
            $writer->addRow(new Row([]));

            $writer->addRow(new Row(array_map(
                fn (string $label) => Cell::fromValue($label, $bold),
                $sheet->labels(),
            )));

            foreach ($sheet->rows as $row) {
                $writer->addRow($this->row($sheet, $row, $numeric));
            }

            foreach ($sheet->totals as $total) {
                $writer->addRow($this->row($sheet, $total, $numeric, $bold));
            }

            $writer->addRow(new Row([]));
            foreach ($sheet->footerLines() as $line) {
                $writer->addRow(new Row([Cell::fromValue($line)]));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /** @param  array<string, mixed>  $row */
    private function row(ReportSheet $sheet, array $row, bool $numeric, ?Style $style = null): Row
    {
        $cells = [];

        foreach ($sheet->columns as $column) {
            $raw = $row[$column->key] ?? null;

            $cells[] = $numeric && $column->isNumeric()
                ? Cell::fromValue($column->numericValue($raw), $style)
                : Cell::fromValue($column->displayValue($raw), $style);
        }

        return new Row($cells);
    }
}
