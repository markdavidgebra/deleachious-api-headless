<?php

use App\Http\Controllers\DeleteAccountController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/privacy-policy', 'privacy-policy');

Route::get('/delete-account', [DeleteAccountController::class, 'show'])->name('delete-account');
