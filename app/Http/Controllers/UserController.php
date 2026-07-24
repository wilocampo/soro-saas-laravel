<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): Response
    {
        // DB-per-tenant: inside a tenant, the `users` table IS that tenant's
        // users — there is no `tenant_id` column to filter on, and asking for
        // one is a 500. (`users.tenant_id` is landlord-only scaffolding left
        // from the abandoned shared-database design.)
        $query = currentTenant()
            ? User::with('roles')->where('is_system', false)
            : User::with(['roles', 'tenant']);

        $users = $query->paginate(10);
        $roles = Role::all();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roles,
            'isLandlord' => ! currentTenant(),
        ]);
    }

    public function create(): Response
    {
        $roles = Role::all();

        return Inertia::render('Users/Create', [
            'roles' => $roles,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'roles' => 'array',
            'roles.*' => 'exists:roles,id',
            'tenant_id' => 'nullable|exists:tenants,id', // For landlord context
        ]);

        $userData = [
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ];

        // Only the landlord `users` table has a tenant_id; inside a tenant DB
        // the database itself is the scope (see index()).
        if (! currentTenant()) {
            $userData['tenant_id'] = $request->tenant_id;
        }

        $user = User::create($userData);

        if ($request->roles) {
            $user->assignRole($request->roles);
        }

        return redirect()->route('users.index')
            ->with('success', 'User created successfully.');
    }

    public function show(User $user): Response
    {
        $user->load(currentTenant() ? ['roles'] : ['roles', 'tenant']);

        return Inertia::render('Users/Show', [
            'user' => $user,
        ]);
    }

    public function edit(User $user): Response
    {
        $roles = Role::all();
        $user->load('roles');

        return Inertia::render('Users/Edit', [
            'user' => $user,
            'roles' => $roles,
        ]);
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'password' => 'nullable|string|min:8|confirmed',
            'roles' => 'array',
            'roles.*' => 'exists:roles,id',
        ]);

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
        ]);

        if ($request->password) {
            $user->update(['password' => Hash::make($request->password)]);
        }

        if ($request->has('roles')) {
            $user->syncRoles($request->roles);
        }

        return redirect()->route('users.index')
            ->with('success', 'User updated successfully.');
    }

    public function destroy(User $user)
    {
        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'User deleted successfully.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:users,id',
        ]);

        /** @var array<int, int> $requestedIds */
        $requestedIds = $validated['ids'];

        // Never let a bulk action remove the operator's own account.
        $ids = collect($requestedIds)->reject(fn ($id) => (int) $id === $request->user()->id);

        User::whereIn('id', $ids)->delete();

        return redirect()->route('users.index')
            ->with('success', $ids->count().' user(s) deleted.');
    }
}
