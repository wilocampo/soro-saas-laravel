<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            // The navigation has to know which half of the app it is in:
            // landlord routes (/tenants, /users, /settings) and tenant routes
            // (/onboarding, /tenant-users, the whole ledger) are different
            // route groups against different databases. Offering a landlord
            // link inside a tenant is a 500, because the landlord tables are
            // not in the tenant's database.
            'tenancy' => fn () => [
                'isTenant' => currentTenant() !== null,
                'name' => currentTenant()?->name,
            ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            // Topbar bell: unread count + the five most recent.
            'notificationsMenu' => fn () => $request->user() ? [
                'unreadCount' => $request->user()->unreadNotifications()->count(),
                'recent' => $request->user()->notificationSummaries(5),
            ] : null,
            // Flash bridge (spec 11 §2 rule 4): controllers redirect with
            // ->with('success'|'error'|...); AuthenticatedLayout toasts them.
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
            ],
        ];
    }
}
