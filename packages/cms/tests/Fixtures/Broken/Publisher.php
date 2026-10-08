<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

/* A field stored where Mainstay keeps who published the live version.
   Named in snake case: ContentType's own $publishedBy holds the other
   spelling to its type. */
class Publisher extends Entry
{
    #[Text]
    public ?string $published_by;
}
