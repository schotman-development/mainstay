<?php

namespace Mainstay\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Mainstay\Content\Document;
use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Date;
use Mainstay\Fields\Internal;
use Mainstay\Fields\RichText;
use Mainstay\Fields\Terms;
use Mainstay\Fields\Text;
use Mainstay\Tests\Fixtures\Blocks\Callout;
use Mainstay\Tests\Fixtures\Blocks\Figure;
use Mainstay\Tests\Fixtures\Blocks\Gallery;
use Mainstay\Tests\Fixtures\Linked\Blocks\Shelf;
use Mainstay\Tests\Fixtures\Linked\Genre;

/* A page the Content tab draws: blocks in its content -- one with a list,
   one with an image, one with a list of relations -- a date it cannot be
   drawn without, rich text beside the content, terms, and a note only some
   callers see. Drawn by spread.blade.php, which shows who it is drawn for
   and the other spreads' content. */
#[Route('/spreads/{slug}')]
#[Blocks([Callout::class, Gallery::class, Figure::class, Shelf::class])]
class Spread extends Entry
{
    #[Text(required: true)]
    public string $slug;

    #[Date(required: true)]
    public CarbonImmutable $issuedOn;

    #[RichText]
    public ?Document $aside;

    #[Terms(of: Genre::class)]
    public array $genres;

    #[Text]
    #[Internal]
    public ?string $memo;
}
