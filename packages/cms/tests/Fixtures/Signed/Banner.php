<?php

namespace Mainstay\Tests\Fixtures\Signed;

use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Text;

class Banner extends GlobalSet
{
    #[Text]
    public ?string $text;
}
