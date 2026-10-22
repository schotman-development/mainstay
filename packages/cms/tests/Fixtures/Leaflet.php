<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Document;
use Mainstay\Content\Entry;
use Mainstay\Fields\Content;

/* One content for every language: Entry's own, redeclared without
   `localized`, as a title can be. */
class Leaflet extends Entry
{
    #[Content]
    public ?Document $content;
}
