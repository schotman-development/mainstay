<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Blocks;
use Mainstay\Tests\Fixtures\Blocks\Slide;

/* A global holding a list of blocks, which only a block holds. */
class ListedSettings extends GlobalSet
{
    #[Blocks(of: [Slide::class])]
    public array $slides;
}
