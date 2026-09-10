<?php

namespace Mainstay\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string version()
 * @method static void types(array $types)
 * @method static array registered()
 * @method static array fields(string $type)
 * @method static array schema(string $type)
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
