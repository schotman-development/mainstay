<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Content\Media;
use Mainstay\Fields\Image;

class Framed extends Entry
{
    #[Image(required: true, sizes: ['card' => [40, 20]])]
    public Media $cover;
}
