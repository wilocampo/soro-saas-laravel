<?php

namespace App\Console\Commands;

use App\Domain\Documents\Import\ChartOfAccountsImporter;
use App\Domain\Documents\Import\OpenDocumentImporter;
use App\Domain\Documents\Import\PartnerImporter;
use Illuminate\Console\Command;

/**
 * Cutover import (docs/specs/02 §4.2). Run in this order — open documents
 * reference partners, and partners reference nothing:
 *
 *   documents:import accounts  chart.csv
 *   documents:import partners  partners.csv
 *   documents:import open      open-documents.csv
 *   ledger:opening-balances    (posts the A/R and A/P control totals)
 *
 * Then `--verify` proves the imported detail ties to the opening balance.
 */
class DocumentsImportCommand extends Command
{
    protected $signature = 'documents:import
        {type : accounts|partners|open}
        {file : path to the CSV}
        {--verify : after an "open" import, tie the detail to the opening balance}';

    protected $description = 'Import chart of accounts, partners, or open documents from CSV';

    public function handle(): int
    {
        $type = $this->argument('type');
        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("No such file: {$file}");

            return self::FAILURE;
        }

        $result = match ($type) {
            'accounts' => app(ChartOfAccountsImporter::class)->import($file),
            'partners' => app(PartnerImporter::class)->import($file),
            'open' => app(OpenDocumentImporter::class)->import($file),
            default => null,
        };

        if ($result === null) {
            $this->error("Unknown import type [{$type}] — use accounts, partners or open.");

            return self::FAILURE;
        }

        $this->info($result->summary());

        foreach ($result->errors as $error) {
            $this->warn("  row {$error['row']}: {$error['message']}");
        }

        if ($type === 'open' && $this->option('verify')) {
            $this->verify();
        }

        // Row errors are a partial import, not a crash: the good rows landed.
        return $result->isClean() ? self::SUCCESS : self::FAILURE;
    }

    private function verify(): void
    {
        $importer = app(OpenDocumentImporter::class);

        foreach ([['sales_invoice', '1100', 'A/R'], ['vendor_bill', '2000', 'A/P']] as [$type, $code, $label]) {
            $tie = $importer->verifyAgainstOpeningBalance($type, $code);

            $line = sprintf(
                '%s: documents %s vs opening balance %s (difference %s)',
                $label,
                number_format($tie['documents'] / 100, 2),
                number_format($tie['control'] / 100, 2),
                number_format($tie['difference'] / 100, 2),
            );

            $tie['difference'] === 0 ? $this->info("  ✓ {$line}") : $this->error("  ✗ {$line}");
        }
    }
}
