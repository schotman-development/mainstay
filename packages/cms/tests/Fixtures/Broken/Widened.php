<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Select;

class Widened extends Entry
{
    #[Select(options: ['a', 'b'])]
    public int|float $choice;
}
