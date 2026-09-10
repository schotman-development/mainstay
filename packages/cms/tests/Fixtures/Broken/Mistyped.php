<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Number;

class Mistyped extends Entry
{
    #[Number]
    public string $sku;
}
