<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Content\Media;
use Mainstay\Fields\Image;
use Mainstay\Fields\Text;
use Mainstay\Fields\Textarea;

/* Text @field prints: a line, and lines; and an image, which it does not. */
class Quote extends Block
{
    #[Text(required: true)]
    public string $line;

    #[Textarea]
    public ?string $lines;

    #[Image(sizes: ['thumb' => [16, 16]])]
    public ?Media $picture;
}
