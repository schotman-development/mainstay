<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Blocks;

/* A strip of figures, held inside it. */
#[Blocks([Figure::class])]
class Strip extends Block {}
