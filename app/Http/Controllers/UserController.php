<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): Response
    {
        $query = User::with(['roles', 'tenant']);
        
        // If we're in a tenant context, filter by tenant
        if (currentTenant()) {
            $query->where('tenant_id', currentTenant()->id);
        }
        // If we're in landlord context, show all users
        else {
            // Show all users with their tenants
        }
        
        $users = $query->paginate(10);
        $roles = Role::all();
        
        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roles,
            'isLandlord' => !currentTenant()
        ]);
    }

    public function create(): Response
    {
        $roles = Role::all();
        
        return Inertia::render('Users/Create', [
            'roles' => $roles
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

        // Set tenant_id based on context
        if (currentTenant()) {
            // In tenant context, use current tenant
            $userData['tenant_id'] = currentTenant()->id;
        } else {
            // In landlord context, use provided tenant_id or null
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
        $user->load(['roles', 'tenant']);
        
        return Inertia::render('Users/Show', [
            'user' => $user
        ]);
    }

    public function edit(User $user): Response
    {
        $roles = Role::all();
        $user->load('roles');
        
        return Inertia::render('Users/Edit', [
            'user' => $user,
            'roles' => $roles
        ]);
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
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
}