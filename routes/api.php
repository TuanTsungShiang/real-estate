<?php

use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::get('/transactions', [TransactionController::class, 'apiIndex']);
Route::get('/transactions/summary', [TransactionController::class, 'summary']);
Route::get('/transactions/trend', [TransactionController::class, 'trend']);
