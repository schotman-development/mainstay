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
 | The argument and the property type agree wherever both speak, so the fixture
 | checks the reflection rather than the presence rule. `featured` is the one
 | non-nullable property whose attribute stays silent, because `required` on a
 | boolean asks whether the box must be ticked and a featured flag does not
 | have to be.
 */
class Article extends Entry
{
    #[Text(max: 120, required: true)]
    public string $title;

    #[Textarea(localized: true)]
    public ?string $summary;

    #[Number(min: 1, required: true)]
    public int $readingMinutes;

    #[Boolean]
    public bool $featured;

    #[Date(time: true)]
    public ?CarbonImmutable $publishedAt;

    #[Select(options: ['draft' => 'Draft', 'review' => 'In review', 'live' => 'Live'], required: true)]
    public string $status;

    /* No attribute, so not a field. */
    public string $renderedHtml = '';
}
