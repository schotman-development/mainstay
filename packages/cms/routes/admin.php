<?php

use Illuminate\Support\Facades\Route;
use Mainstay\Http\Authenticate;
use Mainstay\Http\AuthenticateSession;
use Mainstay\Http\LoginController;
use Mainstay\Http\PasswordController;

Route::get('login', [LoginController::class, 'show'])->name('mainstay.login');
Route::post('login', [LoginController::class, 'store']);
Route::post('logout', [LoginController::class, 'destroy'])->name('mainstay.logout');

Route::get('forgot-password', [PasswordController::class, 'request'])->name('mainstay.password.request');
Route::post('forgot-password', [PasswordController::class, 'email'])->name('mainstay.password.email');
Route::get('reset-password/{token}', [PasswordController::class, 'edit'])->name('mainstay.password.reset');
Route::post('reset-password', [PasswordController::class, 'update'])->name('mainstay.password.update');

Route::view('/{path?}', 'mainstay::admin')
    ->where('path', '.*')
    ->middleware([Authenticate::class, AuthenticateSession::class])
    ->name('mainstay.admin');
