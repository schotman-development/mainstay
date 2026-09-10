<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

class Hidden extends Entry
{
    #[Text]
    protected string $secret;
}
