<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/notifications', function () {
    return Inertia::render('Notifications');
})->middleware(['auth', 'verified'])->name('notifications');

// Test route to verify routing works
Route::get('/test-users', function () {
    return response()->json(['message' => 'Users route is working', 'tenant' => currentTenant()?->name ?? 'No tenant']);
})->middleware(['auth', 'verified', 'landlord'])->name('test.users');

// Tenant management routes (landlord only)
Route::middleware(['auth', 'verified', 'landlord'])->group(function () {
    Route::resource('tenants', App\Http\Controllers\TenantController::class);
    // Users management from landlord perspective
    Route::resource('users', App\Http\Controllers\UserController::class);
    Route::get('/settings', [App\Http\Controllers\SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update');
});

// Tenant-specific routes (when accessing from tenant subdomain)
Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::resource('tenant-users', App\Http\Controllers\UserController::class)->names([
        'index' => 'tenant.users.index',
        'create' => 'tenant.users.create',
        'store' => 'tenant.users.store',
        'show' => 'tenant.users.show',
        'edit' => 'tenant.users.edit',
        'update' => 'tenant.users.update',
        'destroy' => 'tenant.users.destroy',
    ]);
    Route::get('/tenant-settings', [App\Http\Controllers\SettingsController::class, 'index'])->name('tenant.settings.index');
    Route::put('/tenant-settings', [App\Http\Controllers\SettingsController::class, 'update'])->name('tenant.settings.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
