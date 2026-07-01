<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SupplyChain\DashboardController;
use App\Http\Controllers\SupplyChain\WorkspaceController;
use App\Http\Controllers\SupplyChain\BookingController;
use App\Http\Controllers\SupplyChain\PaymentRequestController;

Route::prefix('supply-chain')
    ->middleware(['auth', 'role:supply_chain'])
    ->name('supply_chain.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');


        Route::get('/payment-requests', [PaymentRequestController::class, 'index'])->name('payment_requests.index');
        Route::get('/payment-requests/create', [PaymentRequestController::class, 'create'])->name('payment_requests.create');
        Route::get('/payment-requests/preview', [PaymentRequestController::class, 'preview'])->name('payment_requests.preview');
        Route::post('/payment-requests', [PaymentRequestController::class, 'store'])->name('payment_requests.store');
        Route::get('/payment-requests/{paymentRequest}', [PaymentRequestController::class, 'show'])->name('payment_requests.show');
        Route::get('/payment-requests/{paymentRequest}/download-pdf', [PaymentRequestController::class, 'downloadPdf'])->name('payment_requests.download_pdf');
        Route::get('/payment-requests/{paymentRequest}/download-excel', [PaymentRequestController::class, 'downloadExcel'])->name('payment_requests.download_excel');
        Route::post('/payment-requests/{paymentRequest}/email', [PaymentRequestController::class, 'sendEmail'])->name('payment_requests.email');

        Route::get('/booking-generate', [BookingController::class, 'index'])->name('bookings.index');
        Route::get('/booking-generate/data', [BookingController::class, 'data'])->name('bookings.data');
        Route::post('/booking-generate/bulk-preview', [BookingController::class, 'bulkPreview'])->name('bookings.bulk_preview');
        Route::post('/booking-generate/bulk-generate', [BookingController::class, 'bulkGenerate'])->name('bookings.bulk_generate');
        Route::post('/booking-generate/batch-preview', [BookingController::class, 'batchPreview'])->name('bookings.batch_preview');
        Route::post('/booking-generate/batch-generate', [BookingController::class, 'batchGenerate'])->name('bookings.batch_generate');
        Route::post('/booking-generate/bulk-complete', [BookingController::class, 'bulkComplete'])->name('bookings.bulk_complete');
        Route::post('/booking-generate/{excelRow}/preview', [BookingController::class, 'preview'])->name('bookings.preview');
        Route::post('/booking-generate/{excelRow}', [BookingController::class, 'generate'])->name('bookings.generate');
        Route::post('/booking-generate/po/{bookingPo}/regenerate-preview', [BookingController::class, 'regeneratePreview'])->name('bookings.regenerate_preview');
        Route::post('/booking-generate/po/{bookingPo}/regenerate', [BookingController::class, 'regenerate'])->name('bookings.regenerate');
        Route::get('/booking-generate/po/{bookingPo}', [BookingController::class, 'show'])->name('bookings.show');
        Route::put('/booking-generate/po/{bookingPo}', [BookingController::class, 'update'])->name('bookings.update');
        Route::post('/booking-generate/po/{bookingPo}/complete', [BookingController::class, 'complete'])->name('bookings.complete');
        Route::get('/booking-generate/po/{bookingPo}/print', [BookingController::class, 'print'])->name('bookings.print');
        Route::get('/booking-generate/po/{bookingPo}/download', [BookingController::class, 'download'])->name('bookings.download');
        Route::get('/booking-generate/po/{bookingPo}/download-excel', [BookingController::class, 'downloadExcel'])->name('bookings.download_excel');
        Route::post('/booking-generate/po/{bookingPo}/email', [BookingController::class, 'sendEmail'])->name('bookings.email');
    });
