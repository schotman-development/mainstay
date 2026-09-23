<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Internal;

class Unfielded extends Entry
{
    #[Internal]
    public string $costPrice;
}
