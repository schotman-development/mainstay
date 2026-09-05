<?php

use Illuminate\Support\Facades\Route;
use Mainstay\Mainstay;

Route::get('/', fn () => [
    'name' => 'mainstay',
    'version' => Mainstay::VERSION,
])->name('mainstay.api.index');
