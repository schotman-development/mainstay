<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;
use Mainstay\Fields\Textarea;

abstract class BaseNote extends Entry
{
    #[Textarea]
    protected string $body;

    #[Text]
    protected string $heading;
}
