<?php

use App\Http\Controllers\KsefCertificateController;
use App\Http\Controllers\KsefController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('ksef/setup', [KsefCertificateController::class, 'create'])->name('ksef.setup.create');
    Route::post('ksef/setup', [KsefCertificateController::class, 'store'])->name('ksef.setup.store');
    Route::post('ksef/setup/{type}', [KsefCertificateController::class, 'update'])->name('ksef.setup.update');
    Route::delete('ksef/setup/{type}', [KsefCertificateController::class, 'destroy'])->name('ksef.setup.destroy');
    Route::get('ksef/setup/{type}/{fileType}', [KsefCertificateController::class, 'download'])->name('ksef.setup.download');
});

Route::middleware(['auth', 'verified', 'ksef.certificate'])->group(function () {
    Route::get('dashboard', [KsefController::class, 'dashboard'])->name('dashboard');

    // KSeF routes
    Route::prefix('ksef')->name('ksef.')->group(function () {
        Route::get('/', [KsefController::class, 'index'])->name('index');
        Route::get('/status', [KsefController::class, 'status'])->name('status');
        Route::post('/authenticate', [KsefController::class, 'authenticate'])->name('authenticate');
        Route::post('/keep-alive', [KsefController::class, 'keepAlive'])->name('keep-alive');
        Route::post('/logout', [KsefController::class, 'logout'])->name('logout');
        Route::post('/session/clear', [KsefController::class, 'clearSession'])->name('session.clear');
        Route::post('/invoices/search', [KsefController::class, 'searchInvoices'])->name('invoices.search');
        Route::get('/my-invoices', [KsefController::class, 'myInvoices'])->name('my-invoices');
        Route::get('/my-invoices/data', [KsefController::class, 'myInvoicesData'])->name('my-invoices.data');
        Route::get('/invoices/{ksefNumber}', [KsefController::class, 'getInvoice'])->name('invoices.show');
        Route::get('/invoices/{ksefNumber}/download', [KsefController::class, 'downloadInvoice'])->name('invoices.download');
        Route::get('/sessions', [KsefController::class, 'sessions'])->name('sessions');
        Route::get('/sessions/{referenceNumber}/invoices', [KsefController::class, 'sessionInvoices'])->name('sessions.invoices');
    });
});

require __DIR__.'/settings.php';
