<?php

use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/transactions');
Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
