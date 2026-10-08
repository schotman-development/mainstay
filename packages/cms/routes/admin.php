<?php

use Illuminate\Support\Facades\Route;
use Mainstay\Http\Authenticate;
use Mainstay\Http\AuthenticateSession;
use Mainstay\Http\EntryController;
use Mainstay\Http\LoginController;
use Mainstay\Http\MediaController;
use Mainstay\Http\PasswordController;

Route::get('login', [LoginController::class, 'show'])->name('mainstay.login');
Route::post('login', [LoginController::class, 'store']);
Route::post('logout', [LoginController::class, 'destroy'])->name('mainstay.logout');

Route::get('forgot-password', [PasswordController::class, 'request'])->name('mainstay.password.request');
Route::post('forgot-password', [PasswordController::class, 'email'])->name('mainstay.password.email');
Route::get('reset-password/{token}', [PasswordController::class, 'edit'])->name('mainstay.password.reset');
Route::post('reset-password', [PasswordController::class, 'update'])->name('mainstay.password.update');

/*
 | Every screen under a type's handle, which registration keeps from being
 | `login`, `logout` or `media`. A path none of them answers is the admin's
 | own not-found, drawn in the shell: named as the others are, so no entry
 | can be given a path anywhere under the prefix.
 */
Route::middleware([Authenticate::class, AuthenticateSession::class])->group(function () {
    Route::view('/', 'mainstay::dashboard')->name('mainstay.admin');

    Route::get('media', [MediaController::class, 'index'])->name('mainstay.media');
    Route::post('media', [MediaController::class, 'store'])->name('mainstay.media.store');
    Route::get('media/trash', [MediaController::class, 'trashed'])->name('mainstay.media.trash');
    Route::get('media/{id}', [MediaController::class, 'edit'])->whereNumber('id')->name('mainstay.media.edit');
    Route::post('media/{id}', [MediaController::class, 'update'])->whereNumber('id')->name('mainstay.media.update');
    Route::post('media/{id}/trash', [MediaController::class, 'trash'])->whereNumber('id')->name('mainstay.media.delete');
    Route::post('media/{id}/restore', [MediaController::class, 'restore'])->whereNumber('id')->name('mainstay.media.restore');
    Route::post('media/{id}/destroy', [MediaController::class, 'destroy'])->whereNumber('id')->name('mainstay.media.destroy');

    Route::get('{type}', [EntryController::class, 'index'])->name('mainstay.entries');
    Route::post('{type}', [EntryController::class, 'store'])->name('mainstay.entries.store');
    Route::get('{type}/new', [EntryController::class, 'create'])->name('mainstay.entries.create');
    Route::get('{type}/trash', [EntryController::class, 'trashed'])->name('mainstay.entries.trash');
    Route::post('{type}/trash', [EntryController::class, 'trashMany'])->name('mainstay.entries.trash-many');

    Route::get('{type}/drafts/{draft}', [EntryController::class, 'draft'])->whereNumber('draft')->name('mainstay.drafts.edit');
    Route::post('{type}/drafts/{draft}', [EntryController::class, 'saveDraft'])->whereNumber('draft')->name('mainstay.drafts.update');
    Route::post('{type}/drafts/{draft}/discard', [EntryController::class, 'discard'])->whereNumber('draft')->name('mainstay.drafts.discard');
    Route::get('{type}/relations/{field}', [EntryController::class, 'relations'])->name('mainstay.relations');

    Route::get('{type}/{id}', [EntryController::class, 'edit'])->whereNumber('id')->name('mainstay.entries.edit');
    Route::post('{type}/{id}', [EntryController::class, 'update'])->whereNumber('id')->name('mainstay.entries.update');
    Route::post('{type}/{id}/trash', [EntryController::class, 'trash'])->whereNumber('id')->name('mainstay.entries.delete');
    Route::post('{type}/{id}/restore', [EntryController::class, 'restore'])->whereNumber('id')->name('mainstay.entries.restore');
    Route::post('{type}/{id}/destroy', [EntryController::class, 'destroy'])->whereNumber('id')->name('mainstay.entries.destroy');
    Route::post('{type}/{id}/front', [EntryController::class, 'front'])->whereNumber('id')->name('mainstay.entries.front');

    Route::any('{path}', fn () => response()->view('mainstay::missing', status: 404))->where('path', '.*')->name('mainstay.missing');
});
