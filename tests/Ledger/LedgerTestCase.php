<?php

namespace Tests\Ledger;

use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\JournalLineDraft;
use App\Domain\Ledger\Posting\SourceRef;
use App\Domain\Ledger\PostingService;
use Carbon\CarbonImmutable;
use Database\Seeders\Tenant\LedgerCoreSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Base for the strict Ledger suite (docs/specs/05). Runs the REAL tenant
 * migrations on the default test connection:
 *  - sqlite :memory: (fast suite): schema is rebuilt per test (the in-memory
 *    DB dies with each app teardown anyway); trigger/CHECK tests skip.
 *  - MariaDB (CI service / dev): schema is migrated once per process and
 *    each test runs inside a rolled-back transaction; triggers + CHECK
 *    constraints are LIVE, so the DB safety net is exercised for real.
 */
abstract class LedgerTestCase extends TestCase
{
    protected static bool $schemaReady = false;

    protected bool $usesTransaction = false;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->inMemorySqlite()) {
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations/tenant', '--force' => true]);
        } elseif (! static::$schemaReady) {
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations/tenant', '--force' => true]);
            static::$schemaReady = true;
        }

        if (! $this->inMemorySqlite()) {
            $this->resetSmallCounters();
            DB::beginTransaction();
            $this->usesTransaction = true;
        }

        $this->seedLedger();
    }

    protected function tearDown(): void
    {
        if ($this->usesTransaction) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    /**
     * MariaDB does not roll back AUTO_INCREMENT, so re-seeding the reference
     * tables once per test walks their counters up forever — and these tables
     * are deliberately narrow (`account_types` is a TINYINT: five rows for
     * the life of a tenant). Left alone the suite dies of "out of range" the
     * moment it passes 255 tests. The rollback already emptied them, so
     * rewinding the counters here is safe and keeps ids stable across runs.
     */
    protected function resetSmallCounters(): void
    {
        foreach ([
            'account_types', 'accounts', 'fiscal_years', 'fiscal_periods',
            'branches', 'document_sequences', 'serial_sequences', 'tax_codes',
        ] as $table) {
            DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
        }
    }

    protected function inMemorySqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite'
            && config('database.connections.'.DB::getDefaultConnection().'.database') === ':memory:';
    }

    protected function requiresMariaDb(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB (triggers/CHECK constraints) — runs on the CI mariadb job.');
        }
    }

    protected function seedLedger(): void
    {
        (new LedgerCoreSeeder)->run();

        DB::table('users')->insert([
            'name' => 'System',
            'email' => 'system@internal.invalid',
            'password' => Hash::make('irrelevant'),
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function posting(): PostingService
    {
        return app(PostingService::class);
    }

    protected function accountId(string $code): int
    {
        return (int) DB::table('accounts')->where('code', $code)->value('id');
    }

    /** @param  array<int, array{string, int, int}>  $lines  [code, debit, credit] */
    protected function draft(array $lines, array $overrides = []): JournalDraft
    {
        static $sequence = 0;
        $sequence++;

        return new JournalDraft(
            journalBook: $overrides['journalBook'] ?? 'general',
            entryDate: $overrides['entryDate'] ?? CarbonImmutable::now(),
            memo: $overrides['memo'] ?? 'test entry',
            source: $overrides['source'] ?? SourceRef::none(),
            idempotencyKey: $overrides['idempotencyKey'] ?? 'test:'.static::class.':'.$this->name().':'.$sequence,
            lines: array_map(fn (array $l) => new JournalLineDraft(
                accountId: $this->accountId($l[0]),
                debitCentavos: $l[1],
                creditCentavos: $l[2],
            ), $lines),
            reverses: $overrides['reverses'] ?? null,
            fiscalPeriodId: $overrides['fiscalPeriodId'] ?? null,
        );
    }

    /** Σ signed balance across all posted lines must be zero. */
    protected function assertTrialBalanceZero(): void
    {
        $sums = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', ['posted', 'void'])
            ->selectRaw('COALESCE(SUM(jl.debit_centavos),0) AS d, COALESCE(SUM(jl.credit_centavos),0) AS c')
            ->first();

        $this->assertSame((int) $sums->d, (int) $sums->c, 'Trial balance does not net to zero.');
    }
}
