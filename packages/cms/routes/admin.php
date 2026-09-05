<?php

use Illuminate\Support\Facades\Route;

Route::view('/{path?}', 'mainstay::admin')
    ->where('path', '.*')
    ->name('mainstay.admin');
