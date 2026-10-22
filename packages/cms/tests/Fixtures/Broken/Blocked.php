<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Blocks;
use Mainstay\Tests\Fixtures\Blocks\Slide;

/* A list of blocks beside its content, where an entry's blocks go. */
class Blocked extends Entry
{
    #[Blocks(of: [Slide::class])]
    public array $slides;
}
