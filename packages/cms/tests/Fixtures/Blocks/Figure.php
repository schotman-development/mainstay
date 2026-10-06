<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Content\Media;
use Mainstay\Fields\Image;

class Figure extends Block
{
    #[Image(sizes: ['thumb' => [16, 16]])]
    public ?Media $image;
}
