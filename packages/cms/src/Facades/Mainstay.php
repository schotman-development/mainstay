<?php

namespace Mainstay\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string version()
 *
 * @see \Mainstay\Mainstay
 */
class Mainstay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Mainstay\Mainstay::class;
    }
}
