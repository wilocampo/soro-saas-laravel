<?php

namespace App\Tenancy\Backup;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Dump/restore via the mariadb-dump / mariadb CLI (D22).
 * --single-transaction gives a consistent InnoDB snapshot without locking;
 * --triggers/--routines/--events make the T1–T5 safety net restorable.
 */
class MariaDbDumper implements DatabaseDumper
{
    public function dump(Tenant $tenant): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tenant-dump-');

        $process = Process::fromShellCommandline(
            $this->buildDumpCommand($tenant).' | gzip > "${:DUMP_PATH}"',
            null,
            $this->environment(),
            null,
            3600,
        );
        $process->mustRun(null, ['DUMP_PATH' => $path]);

        if (filesize($path) === 0) {
            throw new RuntimeException("Dump for tenant {$tenant->id} produced an empty artifact.");
        }

        return $path;
    }

    /**
     * Public so the flag set is testable — a dump without --triggers or
     * --single-transaction is a compliance bug, not a style choice.
     */
    public function buildDumpCommand(Tenant $tenant): string
    {
        $template = $this->templateConfig();

        return sprintf(
            '%s --host=%s --port=%s --user=%s --single-transaction --triggers --routines --events --no-tablespaces %s',
            config('tenant-backup.dump_binary', 'mariadb-dump'),
            escapeshellarg($template['host'] ?? '127.0.0.1'),
            escapeshellarg((string) ($template['port'] ?? 3306)),
            escapeshellarg($template['username'] ?? 'root'),
            escapeshellarg($tenant->getDatabaseName()),
        );
    }

    public function restore(Tenant $tenant, string $archivePath): string
    {
        $scratch = 'restore_test_'.$tenant->id;
        $templateConnection = $this->templateConnection();

        DB::connection($templateConnection)->statement("DROP DATABASE IF EXISTS `{$scratch}`");
        DB::connection($templateConnection)->statement("CREATE DATABASE `{$scratch}`");

        $template = $this->templateConfig();
        $process = Process::fromShellCommandline(
            sprintf(
                'gunzip -c "${:ARCHIVE_PATH}" | %s --host=%s --port=%s --user=%s %s',
                config('tenant-backup.client_binary', 'mariadb'),
                escapeshellarg($template['host'] ?? '127.0.0.1'),
                escapeshellarg((string) ($template['port'] ?? 3306)),
                escapeshellarg($template['username'] ?? 'root'),
                escapeshellarg($scratch),
            ),
            null,
            $this->environment(),
            null,
            3600,
        );
        $process->mustRun(null, ['ARCHIVE_PATH' => $archivePath]);

        config([
            'database.connections.restore_test' => array_merge(
                $this->templateConfig(),
                ['database' => $scratch],
            ),
        ]);
        DB::purge('restore_test');

        return 'restore_test';
    }

    public function cleanupRestore(string $connection): void
    {
        $scratch = config("database.connections.{$connection}.database");

        DB::purge($connection);
        DB::connection($this->templateConnection())
            ->statement("DROP DATABASE IF EXISTS `{$scratch}`");
    }

    /** @return array<string, string> */
    protected function environment(): array
    {
        return ['MYSQL_PWD' => (string) ($this->templateConfig()['password'] ?? '')];
    }

    /** @return array<string, mixed> */
    protected function templateConfig(): array
    {
        return (array) config('database.connections.'.$this->templateConnection());
    }

    protected function templateConnection(): string
    {
        return config('multitenancy.tenant_database_template_connection', 'mariadb');
    }
}
