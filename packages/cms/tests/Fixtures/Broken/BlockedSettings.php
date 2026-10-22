<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Blocks;
use Mainstay\Tests\Fixtures\Blocks\Slide;

/* Blocks named on a global, which has no content to hold them. */
#[Blocks([Slide::class])]
class BlockedSettings extends GlobalSet {}
