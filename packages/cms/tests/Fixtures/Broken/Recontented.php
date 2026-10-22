<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Document;
use Mainstay\Content\Entry;
use Mainstay\Fields\RichText;

/* Its content redeclared as a rich text field, which the edit screen is
   not built on. */
class Recontented extends Entry
{
    #[RichText(localized: true)]
    public ?Document $content;
}
