<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Select;

/* A default its own options do not hold. */
class Loud extends Block
{
    #[Select(options: ['info', 'warning'])]
    public string $tone = 'loud';
}
