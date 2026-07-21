<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks an authenticated user whose password is past the rotation window
 * (docs/specs/04) — they may only reach the profile/password/logout routes
 * until they change it. Disable with ENFORCE_PASSWORD_ROTATION=false.
 */
class EnsurePasswordIsFresh
{
    /** Routes that must stay reachable so the user can actually fix it. */
    private const ALLOWED = ['profile*', 'password*', 'logout', 'verification*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('compliance.enforce_password_rotation') || $user === null) {
            return $next($request);
        }

        foreach (self::ALLOWED as $pattern) {
            if ($request->routeIs($pattern)) {
                return $next($request);
            }
        }

        $changedAt = $user->password_changed_at ?? $user->created_at;
        if ($changedAt === null) {
            return $next($request);
        }

        $rotationDays = (int) config('compliance.password_rotation_days');
        if ((int) $changedAt->diffInDays(now()) < $rotationDays) {
            return $next($request);
        }

        return redirect()->route('profile.edit')
            ->with('warning', "Your password is older than {$rotationDays} days. Please set a new one to continue.");
    }
}
