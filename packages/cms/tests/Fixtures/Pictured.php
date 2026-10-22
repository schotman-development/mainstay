<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\Media;
use Mainstay\Content\Route;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Image;
use Mainstay\Fields\Text;
use Mainstay\Tests\Fixtures\Blocks\Strip;

/* A cover every write needs, cropped and scaled, and images in its content
   two blocks down, inside a repeater. */
#[Route('/pictured/{slug}')]
#[Blocks([Strip::class])]
class Pictured extends Entry
{
    #[Text(required: true)]
    public string $slug;

    #[Image(required: true, sizes: ['card' => [40, 20], 'wide' => [60]])]
    public ?Media $cover;
}
