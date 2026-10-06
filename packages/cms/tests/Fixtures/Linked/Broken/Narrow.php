<?php

namespace Mainstay\Tests\Fixtures\Linked\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Relation;
use Mainstay\Tests\Fixtures\Linked\Person;
use Mainstay\Tests\Fixtures\Linked\Story;

/* One relation to two types, typed as only one of them. */
class Narrow extends Entry
{
    #[Relation(to: [Person::class, Story::class])]
    public ?Person $pick;
}
