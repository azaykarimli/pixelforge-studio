<?php

use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\QuoteController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/generations')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');

    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('generations.store');

    Route::post('generations/quote', QuoteController::class)
        ->middleware('throttle:240,1')
        ->name('generations.quote');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');

    Route::post('credits', [CreditController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('credits.store');
});

require __DIR__.'/settings.php';
