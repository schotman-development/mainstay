<?php

namespace Mainstay\Tests\Fixtures\Linked;

use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Relation;
use Mainstay\Fields\Terms;
use Mainstay\Fields\Text;
use Mainstay\Tests\Fixtures\Linked\Blocks\Shelf;

/* Every shape a relation takes: one and a list, of one type and of several,
   in a column and two blocks down, and terms. */
#[Route('/stories/{slug}')]
class Story extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[Text(required: true, localized: true)]
    public string $slug;

    #[Relation(to: Person::class)]
    public ?Person $author;

    #[Relation(to: Story::class)]
    public array $related;

    #[Relation(to: [Person::class, Story::class])]
    public Person|Story|null $pick;

    #[Relation(to: [Person::class, Story::class])]
    public array $picks;

    #[Blocks(of: [Shelf::class])]
    public array $blocks;

    #[Terms(of: Genre::class)]
    public array $genres;
}
