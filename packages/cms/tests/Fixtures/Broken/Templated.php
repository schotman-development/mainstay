<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

/* Stored as template, which holds an entry's own view. */
class Templated extends Entry
{
    #[Text]
    public ?string $template;
}
