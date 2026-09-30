<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Blocks;

/* A block that holds itself, which has no end to read. */
class Looped extends Block
{
    #[Blocks(of: [Looped::class])]
    public array $inner;
}
