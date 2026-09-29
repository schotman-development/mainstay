<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

/* Stored as template, which holds an entry's own view, and typed as Entry's
   is not: refused by sync rather than failing to load. */
class Templated extends Entry
{
    #[Text(required: true)]
    public string $template;
}
