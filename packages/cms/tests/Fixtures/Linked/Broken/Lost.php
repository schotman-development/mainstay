<?php

namespace Mainstay\Tests\Fixtures\Linked\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Boolean;

/* A field by the name that marks an entry a relation did not load. */
class Lost extends Entry
{
    #[Boolean]
    public bool $missing;
}
