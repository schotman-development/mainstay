<?php

namespace Mainstay\Tests\Fixtures\Linked\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Blocks;

/* A shelf of spines, held inside it. */
#[Blocks([Spine::class])]
class Shelf extends Block {}
