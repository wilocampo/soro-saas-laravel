<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SingleTenantModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_disabled_in_single_tenant_mode(): void
    {
        config(['app.single_tenant' => true]);

        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'X',
            'email' => 'x@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_registration_remains_open_in_saas_mode(): void
    {
        config(['app.single_tenant' => false]);

        $this->get('/register')->assertOk();
    }

    public function test_app_timezone_is_manila(): void
    {
        // D18: single-market product; period-close day boundaries are Manila dates.
        $this->assertSame('Asia/Manila', config('app.timezone'));
    }
}
