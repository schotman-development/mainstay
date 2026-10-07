<?php

namespace Mainstay\Tests\Fixtures\Drafted;

use Carbon\CarbonImmutable;
use Mainstay\Content\Document;
use Mainstay\Content\Entry;
use Mainstay\Content\Media;
use Mainstay\Content\Route;
use Mainstay\Content\Template;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Boolean;
use Mainstay\Fields\Date;
use Mainstay\Fields\Image;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Relation;
use Mainstay\Fields\RichText;
use Mainstay\Fields\Select;
use Mainstay\Fields\Terms;
use Mainstay\Fields\Text;
use Mainstay\Tests\Fixtures\Blocks\Callout;
use Mainstay\Tests\Fixtures\Linked\Blocks\Shelf;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Linked\Person;
use Mainstay\Tests\Fixtures\Linked\Story;

/* Every kind of field a draft holds, the required ones with no empty value
   among them -- a document, a date, a select, a flag that has to be on --
   a required list, a block with a required field of its own, and a slug
   short enough to run out of room for a suffix. */
#[Route(['en' => '/posts/{slug}', 'nl' => '/berichten/{slug}'])]
#[Template('post', 'special')]
class Bulletin extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[Text(required: true, localized: true, max: 12)]
    public string $slug;

    #[RichText(localized: true)]
    public Document $body;

    #[Date(required: true)]
    public CarbonImmutable $publishedOn;

    #[Select(options: ['news', 'note'])]
    public string $kind;

    #[Boolean(required: true)]
    public bool $approved;

    #[Blocks(of: [Shelf::class, Callout::class])]
    public array $blocks;

    #[Relation(to: [Person::class, Story::class], required: true)]
    public array $picks;

    #[Image(sizes: ['card' => [32, 32]])]
    public ?Media $cover;

    #[Terms(of: Genre::class)]
    public array $genres;

    #[Internal]
    #[Text]
    public ?string $memo;
}
