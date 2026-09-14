<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

abstract class BaseFiled extends Entry
{
    #[Text]
    private string $filed;
}
