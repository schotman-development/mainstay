<?php

namespace Mainstay\Tests\Fixtures\Rich;

use Mainstay\Content\Document;
use Mainstay\Content\Entry;
use Mainstay\Fields\RichText;

/* Prose\Article after its summary became rich text: a column SQLite and SQL
   Server keep as text either way. */
class Article extends Entry
{
    #[RichText]
    public ?Document $summary;
}
