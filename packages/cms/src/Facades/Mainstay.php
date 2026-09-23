<?php

namespace Mainstay\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string version()
 * @method static void types(array $types)
 * @method static array registered()
 * @method static array fields(string $type)
 * @method static array schema(string $type)
 * @method static \Illuminate\Support\Collection find(string $type, array $where = [], string|array $sort = [], ?int $limit = null, ?string $locale = null, bool $overrideAccess = false)
 * @method static \Mainstay\Content\Entry|null findById(string $type, int $id, ?string $locale = null, bool $overrideAccess = false)
 * @method static \Illuminate\Pagination\LengthAwarePaginator paginate(string $type, array $where = [], string|array $sort = [], int $perPage = 15, ?int $page = null, ?string $locale = null, bool $overrideAccess = false)
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
