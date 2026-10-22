<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Text;

/* A block holding blocks of one other kind inside it. */
#[Blocks([Slide::class])]
class Gallery extends Block
{
    #[Text]
    public ?string $caption;
}
