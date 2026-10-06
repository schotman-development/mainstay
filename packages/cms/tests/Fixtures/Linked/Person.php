<?php

namespace Mainstay\Tests\Fixtures\Linked;

use Mainstay\Content\Entry;
use Mainstay\Content\Media;
use Mainstay\Content\Route;
use Mainstay\Fields\Boolean;
use Mainstay\Fields\Image;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Text;

/* What a relation points at: translated, with a note only some readers see
   and an image, so a level of depth has images of its own to load. */
#[Route('/people/{slug}')]
class Person extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[Text(required: true, localized: true)]
    public string $slug;

    #[Internal]
    #[Text]
    public ?string $note;

    #[Image(sizes: ['face' => [32, 32]])]
    public ?Media $portrait;

    /* A default nobody stored, which an entry a read did not load holds
       no more than it holds a title. */
    #[Boolean]
    public bool $listed = true;
}
