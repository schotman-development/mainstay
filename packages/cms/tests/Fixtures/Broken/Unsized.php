<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Content\Media;
use Mainstay\Fields\Image;

class Unsized extends Entry
{
    #[Image]
    public ?Media $cover;
}
