<?php

namespace Mainstay\Tests\Fixtures\Blocks\Nested;

use Mainstay\Content\Block;
use Mainstay\Fields\Blocks;
use Mainstay\Tests\Fixtures\Blocks\Other\Slide as OtherSlide;

/* A slide holding another block called slide. */
class Slide extends Block
{
    #[Blocks(of: [OtherSlide::class])]
    public array $inner;
}
