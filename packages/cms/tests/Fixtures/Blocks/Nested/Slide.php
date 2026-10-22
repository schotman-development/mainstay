<?php

namespace Mainstay\Tests\Fixtures\Blocks\Nested;

use Mainstay\Content\Block;
use Mainstay\Fields\Blocks;
use Mainstay\Tests\Fixtures\Blocks\Other\Slide as OtherSlide;

/* A slide holding another block called slide. */
#[Blocks([OtherSlide::class])]
class Slide extends Block {}
