<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Date;

class Mistimed extends Entry
{
    #[Date]
    public string $when;
}
