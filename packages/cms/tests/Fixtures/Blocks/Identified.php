<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Text;

class Identified extends Block
{
    #[Text]
    public string $id;
}
