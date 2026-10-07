<?php

use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\AgentImportController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BankController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CorrectionReviewController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ManualExtractionController;
use App\Http\Controllers\Admin\ReceivingAccountController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Employee\CorrectionController;
use App\Http\Controllers\Employee\EvidenceController;
use App\Http\Controllers\Employee\HomeController;
use App\Http\Controllers\Employee\TransactionController;
use App\Http\Controllers\EvidenceFileController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
});
Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::get('/evidence/{evidence}', EvidenceFileController::class)->name('evidence.show');

    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/transactions', [AdminTransactionController::class, 'index'])->name('transactions.index');
        Route::get('/transactions/{transaction}', [AdminTransactionController::class, 'show'])->name('transactions.show');
        Route::post('/transactions/{transaction}/external-override', [AdminTransactionController::class, 'externalOverride'])->name('transactions.external-override');
        Route::post('/transactions/{transaction}/reject', [AdminTransactionController::class, 'reject'])->name('transactions.reject');
        Route::post('/transactions/{transaction}/revalidate', [AdminTransactionController::class, 'revalidate'])->name('transactions.revalidate');
        Route::post('/transactions/{transaction}/retry-external', [AdminTransactionController::class, 'retryExternal'])->name('transactions.retry-external');
        Route::post('/evidence/{evidence}/manual-extraction', [ManualExtractionController::class, 'store'])->name('evidence.manual-extraction');

        Route::get('/corrections', [CorrectionReviewController::class, 'index'])->name('corrections.index');
        Route::post('/corrections/{correctionRequest}/approve', [CorrectionReviewController::class, 'approve'])->name('corrections.approve');
        Route::post('/corrections/{correctionRequest}/reject', [CorrectionReviewController::class, 'reject'])->name('corrections.reject');

        Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
        Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
        Route::put('/brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
        Route::get('/banks', [BankController::class, 'index'])->name('banks.index');
        Route::post('/banks', [BankController::class, 'store'])->name('banks.store');
        Route::put('/banks/{bank}', [BankController::class, 'update'])->name('banks.update');
        Route::get('/receiving-accounts', [ReceivingAccountController::class, 'index'])->name('receiving-accounts.index');
        Route::post('/receiving-accounts', [ReceivingAccountController::class, 'store'])->name('receiving-accounts.store');
        Route::put('/receiving-accounts/{receivingAccount}', [ReceivingAccountController::class, 'update'])->name('receiving-accounts.update');
        Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');
        Route::post('/agents', [AgentController::class, 'store'])->name('agents.store');
        Route::get('/agents/{agent}/edit', [AgentController::class, 'edit'])->name('agents.edit');
        Route::put('/agents/{agent}', [AgentController::class, 'update'])->name('agents.update');
        Route::get('/agent-imports', [AgentImportController::class, 'index'])->name('agent-imports.index');
        Route::post('/agent-imports', [AgentImportController::class, 'store'])->name('agent-imports.store');
        Route::get('/agent-imports/{agentImport}', [AgentImportController::class, 'show'])->name('agent-imports.show');
        Route::post('/agent-imports/{agentImport}/confirm', [AgentImportController::class, 'confirm'])->name('agent-imports.confirm');
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::put('/employees/{user}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export.csv', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::post('/sessions/{deviceSession}/revoke', [SessionController::class, 'revoke'])->name('sessions.revoke');
        Route::post('/sessions/users/{user}/revoke-all', [SessionController::class, 'revokeAll'])->name('sessions.revoke-all');
    });

    Route::prefix('employee')->name('employee.')->middleware('role:employee')->group(function () {
        Route::get('/', HomeController::class)->name('home');
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('/transactions/new', [TransactionController::class, 'create'])->name('transactions.create');
        Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
        Route::post('/transactions/{transaction}/agent-evidence', [EvidenceController::class, 'agent'])->middleware('throttle:uploads')->name('transactions.agent-evidence');
        Route::post('/transactions/{transaction}/bank-evidence', [EvidenceController::class, 'banks'])->middleware('throttle:uploads')->name('transactions.bank-evidence');
        Route::post('/evidence/{evidence}/retry', [EvidenceController::class, 'retry'])->name('evidence.retry');
        Route::post('/transactions/{transaction}/reason', [TransactionController::class, 'reason'])->name('transactions.reason');
        Route::post('/transactions/{transaction}/corrections', [CorrectionController::class, 'store'])->name('transactions.corrections');
        Route::post('/transactions/{transaction}/finalize', [TransactionController::class, 'finalize'])->name('transactions.finalize');
        Route::post('/transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])->name('transactions.cancel');
    });
});

Route::get('/', fn () => auth()->check() ? redirect(auth()->user()->isAdmin() ? route('admin.dashboard') : route('employee.home')) : redirect()->route('login'));
