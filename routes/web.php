<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentGateway\StripeController;
use Illuminate\Support\Facades\Artisan;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/clear-cache', function () {
    Artisan::call('cache:clear');
    Artisan::call('config:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');
    Artisan::call('optimize:clear');
      Artisan::call('storage:link');

    return response()->json([
        'status' => 'success',
        'message' => 'All caches cleared successfully'
    ]);
});


// Stripe payment routes
Route::get('/payment/success', [StripeController::class, 'success'])
    ->name('payment.success');
Route::get('/payment/cancel', [StripeController::class, 'cancel'])
    ->name('payment.cancel');

Route::get('/order/status/{order}', [OrderController::class, 'paymentStatus']);
