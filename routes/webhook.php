<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ridley\Laravel\Http\Controllers\WebhookController;
use Ridley\Laravel\Http\Middleware\VerifyRidleySignature;

Route::post('webhook', WebhookController::class)
    ->middleware(VerifyRidleySignature::class)
    ->name('webhook');
