<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The production hardening gate (docs/specs/10 §7).
 *
 * A checklist nobody runs is decoration, so this one has an exit code and
 * these tests prove the dangerous settings actually fail it.
 */
class ProductionCheckTest extends TestCase
{
    use RefreshDatabase;

    private function harden(): void
    {
        config([
            'app.debug' => false,
            'app.env' => 'production',
            'app.timezone' => 'Asia/Manila',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://books.example.ph',
            'telescope.enabled' => false,
            'billing.enabled' => false,
            'app.single_tenant' => false,
        ]);
    }

    public function test_debug_mode_fails_the_check(): void
    {
        $this->harden();
        config(['app.debug' => true]);

        $this->artisan('app:production-check')
            ->expectsOutputToContain('APP_DEBUG is on')
            ->assertFailed();
    }

    /** Telescope records payloads — passwords and TINs in flight. */
    public function test_telescope_enabled_fails_the_check(): void
    {
        $this->harden();
        config(['telescope.enabled' => true]);

        $this->artisan('app:production-check')
            ->expectsOutputToContain('Telescope is enabled')
            ->assertFailed();
    }

    public function test_a_non_https_url_fails_the_check(): void
    {
        $this->harden();
        config(['app.url' => 'http://books.example.ph']);

        $this->artisan('app:production-check')
            ->expectsOutputToContain('APP_URL is not https')
            ->assertFailed();
    }

    /** Entry dates decide fiscal periods and BIR deadlines (D18). */
    public function test_the_wrong_timezone_fails_the_check(): void
    {
        $this->harden();
        config(['app.timezone' => 'UTC']);

        $this->artisan('app:production-check')
            ->expectsOutputToContain('Timezone is not Asia/Manila')
            ->assertFailed();
    }

    public function test_billing_without_a_stripe_secret_fails_the_check(): void
    {
        $this->harden();
        config(['billing.enabled' => true, 'cashier.secret' => null]);

        $this->artisan('app:production-check')
            ->expectsOutputToContain('STRIPE_SECRET is unset')
            ->assertFailed();
    }

    /** A never-backed-up ledger is a compliance problem, not a nag. */
    public function test_a_missing_backup_fails_the_check(): void
    {
        $this->harden();

        $this->artisan('app:production-check')
            ->expectsOutputToContain('No backup has ever run')
            ->assertFailed();
    }
}
