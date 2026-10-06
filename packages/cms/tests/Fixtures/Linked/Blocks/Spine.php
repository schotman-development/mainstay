<?php

namespace Mainstay\Tests\Fixtures\Linked\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Relation;
use Mainstay\Tests\Fixtures\Linked\Story;

class Spine extends Block
{
    #[Relation(to: Story::class)]
    public ?Story $story;
}
