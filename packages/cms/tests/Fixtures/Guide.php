<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Document;
use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Internal;
use Mainstay\Fields\RichText;
use Mainstay\Fields\Text;
use Mainstay\Tests\Fixtures\Blocks\Callout;
use Mainstay\Tests\Fixtures\Blocks\Gallery;

/* Rich text on both tables: a body translated with the title, and an aside
   every locale shares, and notes only some callers see. Blocks in its
   content, translated with it, one of them a repeater, so it has a page. */
#[Route('/guides/{slug}')]
#[Blocks([Callout::class, Gallery::class])]
class Guide extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[Text(required: true)]
    public string $slug;

    #[RichText(localized: true)]
    public ?Document $body;

    #[RichText]
    public ?Document $aside;

    #[RichText]
    #[Internal]
    public ?Document $notes;
}
