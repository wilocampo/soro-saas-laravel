<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\PasswordExpiringNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled password-rotation enforcement (docs/specs/04 §, 10 §5): notify
 * users approaching expiry and report those already past due. Blocking is
 * done by the EnsurePasswordIsFresh middleware; this is the notice half.
 */
class PasswordRotationCheckCommand extends Command
{
    protected $signature = 'users:password-rotation-check {--dry-run : Report without notifying}';

    protected $description = 'Notify users whose password is expiring or expired (spec 04 rotation policy)';

    public function handle(): int
    {
        $rotationDays = (int) config('compliance.password_rotation_days');
        $noticeDays = (int) config('compliance.password_expiry_notice_days');

        $notified = 0;
        $expired = 0;

        // `is_system` is a TENANT-only column (the system actor lives in the
        // tenant DB, D20); on the landlord connection it does not exist.
        $query = User::query();
        if (Schema::hasColumn('users', 'is_system')) {
            $query->where('is_system', false);
        }

        $query->orderBy('id')->chunkById(200, function ($users) use (
            $rotationDays, $noticeDays, &$notified, &$expired
        ): void {
            foreach ($users as $user) {
                // Never rotated → the account's own age is the clock.
                $changedAt = $user->password_changed_at ?? $user->created_at;
                if ($changedAt === null) {
                    continue;
                }

                $daysRemaining = $rotationDays - (int) $changedAt->diffInDays(now());

                if ($daysRemaining > $noticeDays) {
                    continue;
                }

                if ($daysRemaining <= 0) {
                    $expired++;
                }

                if (! $this->option('dry-run')) {
                    $user->notify(new PasswordExpiringNotification(max(0, $daysRemaining)));
                }
                $notified++;
            }
        });

        $this->info("Password rotation: {$notified} user(s) notified, {$expired} already expired.");

        return self::SUCCESS;
    }
}
