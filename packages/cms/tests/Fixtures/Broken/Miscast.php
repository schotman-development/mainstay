<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Boolean;
use Mainstay\Fields\Number;
use Mainstay\Fields\Text;
use Mainstay\Fields\Textarea;

/* Declarations whose cast does not fit the property it hydrates into. Bound one
   at a time, because a field list stops at the first of them. */
class Miscast extends Entry
{
    /* The one that reports nothing without a guard: coercive assignment takes
       the bool from() hands back and stores the string "1". */
    #[Boolean]
    public string $flag;

    #[Text]
    public int $count;

    #[Textarea]
    public array $tags;

    /* A standalone `null`, which is a type PHP allows and no cast has a value
       for. It reaches the guards as the one arm of a one-arm type. */
    #[Number]
    public null $nothing;
}
