<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Document;
use Mainstay\Content\Entry;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Internal;
use Mainstay\Fields\RichText;
use Mainstay\Fields\Text;
use Mainstay\Tests\Fixtures\Blocks\Callout;
use Mainstay\Tests\Fixtures\Blocks\Gallery;

/* Rich text on both tables: a body translated with the title, and an aside
   every locale shares, and notes only some callers see. Blocks translated
   whole, one of them a repeater. */
class Guide extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[RichText(localized: true)]
    public ?Document $body;

    #[RichText]
    public ?Document $aside;

    #[RichText]
    #[Internal]
    public ?Document $notes;

    #[Blocks(of: [Callout::class, Gallery::class], localized: true)]
    public array $blocks;
}
