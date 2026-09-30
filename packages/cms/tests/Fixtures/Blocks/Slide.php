<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Text;

class Slide extends Block
{
    #[Text(required: true)]
    public string $title;
}
