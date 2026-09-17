<?php

namespace Mainstay\Tests\Fixtures\Coded;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

/* A code stored as text, before it is declared a number. */
class Article extends Entry
{
    #[Text]
    public ?string $code;
}
