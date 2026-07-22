<?php

namespace App\Domain\Reports\Export;

use Illuminate\Contracts\View\View;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * One report description, four renderings: HTML, PDF, XLSX and CSV
 * (docs/specs/03 §2). HTML and PDF share the same Blade, so what the
 * operator reads on screen is what prints.
 */
class ReportRenderer
{
    public function __construct(private readonly SpreadsheetExporter $spreadsheet) {}

    public function view(ReportSheet $sheet): View
    {
        return view('reports.sheet', ['sheet' => $sheet]);
    }

    public function html(ReportSheet $sheet): string
    {
        return $this->view($sheet)->render();
    }

    public function pdf(ReportSheet $sheet, string $path): string
    {
        Pdf::view('reports.sheet', ['sheet' => $sheet])
            // Books are wide; portrait would cut the amount columns off.
            ->format('a4')
            ->landscape()
            ->save($path);

        return $path;
    }

    public function xlsx(ReportSheet $sheet, string $path): string
    {
        return $this->spreadsheet->xlsx($sheet, $path);
    }

    public function csv(ReportSheet $sheet, string $path): string
    {
        return $this->spreadsheet->csv($sheet, $path);
    }

    /** @return array{path:string, mime:string, filename:string} */
    public function render(ReportSheet $sheet, string $format, string $basename): array
    {
        $path = tempnam(sys_get_temp_dir(), 'soro-report-');

        return match ($format) {
            'pdf' => ['path' => $this->pdf($sheet, $path), 'mime' => 'application/pdf', 'filename' => "{$basename}.pdf"],
            'xlsx' => [
                'path' => $this->xlsx($sheet, $path),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'filename' => "{$basename}.xlsx",
            ],
            'csv' => ['path' => $this->csv($sheet, $path), 'mime' => 'text/csv', 'filename' => "{$basename}.csv"],
            default => throw new \InvalidArgumentException("Unsupported report format [{$format}]."),
        };
    }
}
