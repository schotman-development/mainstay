<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Text;

class Concealed extends Block
{
    #[Text]
    #[Internal]
    public ?string $note;
}
