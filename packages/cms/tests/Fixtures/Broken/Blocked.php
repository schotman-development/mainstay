<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Blocks;
use Mainstay\Tests\Fixtures\Blocks\Slide;

/* Blocks named on a property, where only a class names them. */
class Blocked extends Entry
{
    #[Blocks(of: [Slide::class])]
    public array $slides;
}
