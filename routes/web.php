<?php

use App\Http\Controllers\KsefController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // KSeF routes
    Route::prefix('ksef')->name('ksef.')->group(function () {
        Route::get('/', [KsefController::class, 'index'])->name('index');
        Route::get('/status', [KsefController::class, 'status'])->name('status');
        Route::post('/authenticate', [KsefController::class, 'authenticate'])->name('authenticate');
        Route::post('/logout', [KsefController::class, 'logout'])->name('logout');
        Route::post('/invoices/search', [KsefController::class, 'searchInvoices'])->name('invoices.search');
        Route::get('/invoices/{ksefNumber}', [KsefController::class, 'getInvoice'])->name('invoices.show');
        Route::get('/invoices/{ksefNumber}/download', [KsefController::class, 'downloadInvoice'])->name('invoices.download');
        Route::get('/sessions', [KsefController::class, 'sessions'])->name('sessions');
        Route::get('/sessions/{referenceNumber}/invoices', [KsefController::class, 'sessionInvoices'])->name('sessions.invoices');
    });
});

require __DIR__.'/settings.php';
