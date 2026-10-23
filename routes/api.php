<?php

use App\Http\Controllers\MockInsurerController;
use App\Http\Controllers\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PolicyFlow API Routes
|--------------------------------------------------------------------------
*/

// Policy Request endpoints
Route::prefix('policy-requests')->group(function () {
    Route::post('/', [PolicyRequestController::class, 'store'])->name('policy-requests.store');
    Route::get('/', [PolicyRequestController::class, 'index'])->name('policy-requests.index');
    Route::get('/{id}', [PolicyRequestController::class, 'show'])->name('policy-requests.show');
    Route::post('/{id}/submit', [PolicyRequestController::class, 'submit'])->name('policy-requests.submit');
});

// Mock Insurer endpoints (deterministic simulation & status lookup)
Route::prefix('mock-insurer')->group(function () {
    Route::post('/issue', [MockInsurerController::class, 'issue'])->name('mock-insurer.issue');
    Route::get('/policies/{reference}', [MockInsurerController::class, 'lookup'])->name('mock-insurer.lookup');
    Route::post('/callback', [PolicyRequestController::class, 'callback'])->name('mock-insurer.callback');
});
