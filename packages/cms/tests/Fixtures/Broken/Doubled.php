<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Number;
use Mainstay\Fields\Text;

class Doubled extends Entry
{
    #[Text]
    #[Number]
    public string $confused;
}
