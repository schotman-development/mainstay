<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Textarea;

abstract class BaseMemo extends Entry
{
    #[Textarea]
    #[Internal]
    protected string $note;
}
