<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

/* A field on a hooked property, which PHP will not unset. */
class Hooked extends Entry
{
    #[Text(required: true)]
    public string $title = 'Untitled' {
        set => trim($value);
    }
}
