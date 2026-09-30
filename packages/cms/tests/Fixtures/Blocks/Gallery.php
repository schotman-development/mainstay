<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Text;

/* A repeater: a block holding a list of one other block. */
class Gallery extends Block
{
    #[Text]
    public ?string $caption;

    #[Blocks(of: [Slide::class], required: true)]
    public array $slides;
}
