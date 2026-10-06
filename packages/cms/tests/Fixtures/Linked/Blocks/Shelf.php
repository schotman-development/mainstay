<?php

namespace Mainstay\Tests\Fixtures\Linked\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Blocks;

/* A repeater of spines. */
class Shelf extends Block
{
    #[Blocks(of: [Spine::class])]
    public array $spines = [];
}
