<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Boolean;
use Mainstay\Fields\Number;
use Mainstay\Fields\Text;

class Slide extends Block
{
    #[Text(required: true)]
    public string $title;

    #[Number]
    public ?float $weight;

    /* A box that must be ticked. */
    #[Boolean(required: true)]
    public bool $shown = true;
}
