<?php

namespace Mainstay\Tests\Fixtures\Linked;

use Mainstay\Content\Entry;
use Mainstay\Fields\Terms;
use Mainstay\Fields\Text;

/* A second type holding a genre's terms, numbering its rows as stories do,
   so the pivot has to tell them apart by type. */
class Review extends Entry
{
    #[Text(localized: true)]
    public ?string $title;

    #[Terms(of: Genre::class)]
    public array $genres;
}
