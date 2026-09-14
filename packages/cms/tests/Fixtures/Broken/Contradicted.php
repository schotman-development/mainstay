<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

class Contradicted extends Entry
{
    #[Text(required: false)]
    public string $title;
}
