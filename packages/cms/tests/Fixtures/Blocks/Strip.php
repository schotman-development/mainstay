<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Blocks;

/* A repeater of figures. */
class Strip extends Block
{
    #[Blocks(of: [Figure::class])]
    public array $figures = [];
}
