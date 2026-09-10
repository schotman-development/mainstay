<?php

namespace Mainstay\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Mainstay\Content\Entry;
use Mainstay\Fields\Boolean;
use Mainstay\Fields\Date;
use Mainstay\Fields\Number;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;
use Mainstay\Fields\Textarea;

/*
 | The fixture phase 1 is checked against: one of every field type that ships,
 | declared the way a host would declare them.
 |
 | Every non-nullable property states `required` and every nullable one does
 | not, so the fixture reads the same under either answer to the open question
 | in Field::isRequired() -- the check is of the reflection, not of that.
 */
class Article extends Entry
{
    #[Text(max: 120, required: true)]
    public string $title;

    #[Textarea(localized: true)]
    public ?string $summary;

    #[Number(min: 1, required: true)]
    public int $readingMinutes;

    #[Boolean(required: true)]
    public bool $featured;

    #[Date(time: true)]
    public ?CarbonImmutable $publishedAt;

    #[Select(options: ['draft' => 'Draft', 'review' => 'In review', 'live' => 'Live'], required: true)]
    public string $status;

    /* No attribute, so not a field. */
    public string $renderedHtml = '';
}
