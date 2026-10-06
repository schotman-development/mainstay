<?php

namespace Mainstay\Tests\Fixtures\Linked\Broken;

use Mainstay\Content\Block;
use Mainstay\Fields\Terms;
use Mainstay\Tests\Fixtures\Linked\Genre;

/* Terms inside a block, where the reverse query could not see them. */
class Labelled extends Block
{
    #[Terms(of: Genre::class)]
    public array $genres = [];
}
