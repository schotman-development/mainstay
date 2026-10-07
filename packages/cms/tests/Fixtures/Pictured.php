<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\Media;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Image;
use Mainstay\Tests\Fixtures\Blocks\Strip;

/* A cover every write needs, cropped and scaled, and images two blocks
   down, inside a repeater. */
class Pictured extends Entry
{
    #[Image(required: true, sizes: ['card' => [40, 20], 'wide' => [60]])]
    public ?Media $cover;

    #[Blocks(of: [Strip::class])]
    public array $blocks;
}
