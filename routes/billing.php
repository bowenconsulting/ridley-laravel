<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ridley\Laravel\Http\Controllers\BillingController;

Route::post('subscribe', [BillingController::class, 'subscribe'])->name('subscribe');
Route::post('preview', [BillingController::class, 'preview'])->name('preview');
Route::put('subscription', [BillingController::class, 'change'])->name('subscription.update');
Route::delete('subscription', [BillingController::class, 'cancel'])->name('subscription.cancel');
Route::post('subscription/resume', [BillingController::class, 'resume'])->name('subscription.resume');
Route::post('payment-method', [BillingController::class, 'paymentMethod'])->name('payment-method');
Route::get('portal', [BillingController::class, 'portal'])->name('portal');
Route::get('invoices', [BillingController::class, 'invoices'])->name('invoices.index');
Route::get('invoices/{invoice}', [BillingController::class, 'invoice'])->name('invoices.show');
Route::put('details', [BillingController::class, 'details'])->name('details');
Route::get('checkout/success', [BillingController::class, 'checkoutSuccess'])->name('checkout.success');
