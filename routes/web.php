<?php

use App\Http\Controllers\AgingController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SalesInvoiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorBillController;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
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

Route::get('/notifications', function (Request $request) {
    // Laravel database notifications; real producers arrive with their
    // features (backup failures, period close, provisioning) — spec 10 §6.
    return Inertia::render('Notifications', [
        'notifications' => $request->user()->notificationSummaries(),
    ]);
})->middleware(['auth', 'verified'])->name('notifications');

Route::post('/notifications/read', function (Request $request) {
    $request->user()->unreadNotifications->markAsRead();

    return back();
})->middleware(['auth', 'verified'])->name('notifications.read');

Route::post('/notifications/{id}/read', function (Request $request, string $id) {
    $request->user()->notifications()->where('id', $id)->first()?->markAsRead();

    return back();
})->middleware(['auth', 'verified'])->name('notifications.read-one');

// Test route to verify routing works
Route::get('/test-users', function () {
    return response()->json(['message' => 'Users route is working', 'tenant' => currentTenant()?->name ?? 'No tenant']);
})->middleware(['auth', 'verified', 'landlord'])->name('test.users');

// Tenant management routes (landlord only)
Route::middleware(['auth', 'verified', 'landlord'])->group(function () {
    Route::resource('tenants', TenantController::class);
    // Users management from landlord perspective
    Route::delete('users-bulk', [UserController::class, 'bulkDestroy'])->name('users.bulk-destroy');
    Route::resource('users', UserController::class);
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});

// Tenant-specific routes (when accessing from tenant subdomain)
Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::resource('tenant-users', UserController::class)->names([
        'index' => 'tenant.users.index',
        'create' => 'tenant.users.create',
        'store' => 'tenant.users.store',
        'show' => 'tenant.users.show',
        'edit' => 'tenant.users.edit',
        'update' => 'tenant.users.update',
        'destroy' => 'tenant.users.destroy',
    ]);
    Route::get('/tenant-settings', [SettingsController::class, 'index'])->name('tenant.settings.index');
    Route::put('/tenant-settings', [SettingsController::class, 'update'])->name('tenant.settings.update');

    // --- documents (Phase 2) ------------------------------------------
    // Ledger tables live in the tenant DB, so every route here is tenant-
    // scoped by construction. No document is ever destroyed: invoices and
    // bills are CANCELLED (CLAUDE.md #4) and partners are deactivated.
    Route::resource('partners', PartnerController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::resource('invoices', SalesInvoiceController::class)->only(['index', 'create', 'store', 'show']);
    Route::get('invoices/{invoice}/pdf', [SalesInvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::post('invoices/{invoice}/email', [SalesInvoiceController::class, 'email'])->name('invoices.email');
    Route::post('invoices/{invoice}/cancel', [SalesInvoiceController::class, 'cancel'])->name('invoices.cancel');

    Route::resource('bills', VendorBillController::class)->only(['index', 'create', 'store', 'show'])
        ->parameters(['bills' => 'bill']);
    Route::post('bills/{bill}/receipt', [VendorBillController::class, 'attachReceipt'])->name('bills.receipt');

    Route::resource('payments', PaymentController::class)->only(['index', 'create', 'store']);
    Route::get('payments/open-documents', [PaymentController::class, 'openDocuments'])->name('payments.open-documents');

    Route::get('/reports/aging', [AgingController::class, 'index'])->name('reports.aging');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
