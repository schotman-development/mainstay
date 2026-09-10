<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Carbon\Carbon;
use Mainstay\Content\Entry;
use Mainstay\Fields\Date;
use Mainstay\Fields\Number;
use Mainstay\Fields\Select;

class Widened extends Entry
{
    #[Select(options: ['a', 'b'])]
    public int|float $choice;

    /* A guard reading the declared type as a single name admits every union,
       not just the `int|float` it was written for -- and 7.5 then coerces into
       whichever arm PHP reaches for. */
    #[Number]
    public string|array $sku;

    /* Not a TypeError even without a guard: Carbon has a __toString, so this
       one holds the date as a string and reports nothing. */
    #[Date]
    public string|int $whenever;

    /* An intersection holds a date only if a CarbonImmutable satisfies every
       arm of it, which is the same question the union asks. */
    #[Date]
    public \Countable&\ArrayAccess $neither;

    /* A union of intersections, which is the shape that reaches the flattening
       twice. */
    #[Date]
    public (\Countable&\ArrayAccess)|null $nested;

    /* Both are dates and neither holds one: a CarbonImmutable is not a
       DateTime and does not extend Carbon. */
    #[Date]
    public \DateTime $aDateTime;

    #[Date]
    public Carbon $aMutableCarbon;
}
