<?php

namespace Mainstay\Tests\Fixtures\Linked\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Relation;
use Mainstay\Tests\Fixtures\Linked\Person;

/* One relation that cannot hold null for none chosen. */
class Demanding extends Entry
{
    #[Relation(to: Person::class)]
    public Person $author;
}
