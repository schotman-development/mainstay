<?php

namespace Mainstay\Tests\Fixtures\Slid;

use Mainstay\Fields\Blocks;
use Mainstay\Tests\Fixtures\Article as BaseArticle;
use Mainstay\Tests\Fixtures\Blocks\Slide;

/* The fixture Article with a list of blocks added, which it must have. */
class Article extends BaseArticle
{
    #[Blocks(of: [Slide::class], required: true)]
    public array $slides;
}
