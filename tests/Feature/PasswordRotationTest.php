<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordExpiringNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Password rotation policy (docs/specs/04, 10 §5). */
class PasswordRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_with_a_fresh_password_is_not_blocked(): void
    {
        $user = User::factory()->create(['password_changed_at' => now()]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_a_user_with_an_expired_password_is_redirected_to_profile(): void
    {
        $user = User::factory()->create([
            'password_changed_at' => now()->subDays(config('compliance.password_rotation_days') + 1),
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('profile.edit'));
    }

    public function test_an_expired_user_can_still_reach_the_profile_and_logout_routes(): void
    {
        $user = User::factory()->create([
            'password_changed_at' => now()->subDays(365),
        ]);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }

    public function test_changing_the_password_starts_a_fresh_rotation_window(): void
    {
        $user = User::factory()->create([
            'password' => 'old-password',
            'password_changed_at' => now()->subDays(365),
        ]);

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'old-password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->password_changed_at->isToday());
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    /**
     * The landlord users table has no `is_system` column (the system actor is
     * tenant-only, D20) — the command guards on that, which is what lets it
     * run against both connections.
     */
    public function test_the_rotation_check_notifies_only_users_inside_the_notice_window(): void
    {
        Notification::fake();

        $expiring = User::factory()->create(['password_changed_at' => now()->subDays(28)]);
        $expired = User::factory()->create(['password_changed_at' => now()->subDays(400)]);
        $fresh = User::factory()->create(['password_changed_at' => now()]);

        $this->artisan('users:password-rotation-check')
            ->expectsOutputToContain('2 user(s) notified, 1 already expired')
            ->assertSuccessful();

        Notification::assertSentTo($expiring, PasswordExpiringNotification::class);
        Notification::assertSentTo($expired, PasswordExpiringNotification::class);
        Notification::assertNotSentTo($fresh, PasswordExpiringNotification::class);
    }
}
