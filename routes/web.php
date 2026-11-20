<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Main Studio Interface
Route::get('/', [GenerationController::class, 'index'])->name('home');
Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');
Route::post('/generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
Route::get('/generations/{generation}/status', [GenerationController::class, 'checkStatus'])->name('generations.status');

// Webhooks (Excluded from CSRF in bootstrap/app.php)
Route::post('/webhooks/pixelforge', [WebhookController::class, 'handle'])->name('webhooks.pixelforge');

// Admin Operator Report & Usage Reconciliation
Route::get('/admin/report', [AdminReportController::class, 'index'])->name('admin.report');

// Balance & Credits (Stretch Goal)
Route::post('/credits/topup', [CreditController::class, 'topup'])->name('credits.topup');
