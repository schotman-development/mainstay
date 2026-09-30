<?php

namespace Mainstay\Tests\Fixtures\Prose;

use Mainstay\Content\Entry;
use Mainstay\Fields\Textarea;

/* A summary kept as plain text, before Rich\Article makes it a document. */
class Article extends Entry
{
    #[Textarea]
    public ?string $summary;
}
