<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\Media;
use Mainstay\Fields\Image;

/* A size no other type declares, for an image uploaded before it was. */
class Poster extends Entry
{
    #[Image(sizes: ['poster' => [24, 36]])]
    public ?Media $image;
}
