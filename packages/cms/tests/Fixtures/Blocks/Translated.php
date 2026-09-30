<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Text;

class Translated extends Block
{
    #[Text(localized: true)]
    public ?string $title;
}
