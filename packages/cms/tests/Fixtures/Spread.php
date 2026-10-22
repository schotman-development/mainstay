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
use Mainstay\Tests\Fixtures\Linked\Genre;

/* A page the Content tab draws: a block in its content, a date it cannot
   be drawn without, rich text beside the content, terms, and a note only
   some callers see. Drawn by spread.blade.php, which shows who it is drawn
   for and the other spreads' content. */
#[Route('/spreads/{slug}')]
#[Blocks([Callout::class])]
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
