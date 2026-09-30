<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Carbon\CarbonImmutable;
use Mainstay\Content\Block;
use Mainstay\Fields\Date;

/* A date that cannot be null and has no default: nothing to read a block
   stored before it was added with. */
class Dated extends Block
{
    #[Date]
    public CarbonImmutable $on;
}
