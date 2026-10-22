<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Blocks;
use Mainstay\Tests\Fixtures\Blocks\Slide;

/* A global naming blocks on a property, where only a class names them. */
class ListedSettings extends GlobalSet
{
    #[Blocks(of: [Slide::class])]
    public array $slides;
}
