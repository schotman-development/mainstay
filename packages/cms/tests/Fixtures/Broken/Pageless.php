<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Blocks;
use Mainstay\Tests\Fixtures\Blocks\Slide;

/* Blocks named for a content with no page to edit them on. */
#[Blocks([Slide::class])]
class Pageless extends Entry {}
