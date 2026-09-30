<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Document;
use Mainstay\Content\Entry;
use Mainstay\Fields\RichText;
use Mainstay\Fields\Text;

/* Rich text on both tables: a body translated with the title, and an aside
   every locale shares. */
class Guide extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[RichText(localized: true)]
    public ?Document $body;

    #[RichText]
    public ?Document $aside;
}
