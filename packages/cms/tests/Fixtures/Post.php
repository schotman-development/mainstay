<?php

namespace Mainstay\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Boolean;
use Mainstay\Fields\Date;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Number;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;
use Mainstay\Fields\Textarea;

/*
 | The fixture phase 3 is checked against: a translated title, slug and
 | status beside shared fields, a path with a translated segment, a readonly
 | property the layer has to initialize from outside the class, and an
 | internal note with a default the layer has to take away again, from a
 | property only the class may write.
 */
#[Route(['en' => '/blog/{slug}', 'nl' => '/nieuws/{slug}'])]
class Post extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[Text(required: true, localized: true)]
    public string $slug;

    #[Select(options: ['draft', 'live'], required: true, localized: true)]
    public string $status;

    #[Date(time: true)]
    public ?CarbonImmutable $publishedAt;

    #[Boolean]
    public bool $featured;

    #[Number]
    public readonly ?int $readingMinutes;

    #[Textarea]
    #[Internal]
    public protected(set) ?string $editorNote = null;
}
