<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

class Shared extends Entry
{
    #[Text]
    public static string $shared;
}
