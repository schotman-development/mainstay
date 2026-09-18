<?php

namespace Mainstay\Tests\Fixtures\Revised;

use Carbon\CarbonImmutable;
use Mainstay\Content\Entry;
use Mainstay\Fields\Boolean;
use Mainstay\Fields\Date;
use Mainstay\Fields\Number;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;
use Mainstay\Fields\Textarea;

/*
 | The fixture Article after an edit sync cannot read the intent of: `title`
 | renamed to `headline`, `readingMinutes` widened from int to float,
 | `publishedAt` made required, and a required date and select added. Same
 | basename, so the same handle and the same tables.
 */
class Article extends Entry
{
    #[Text(max: 120, required: true)]
    public string $headline;

    #[Textarea(localized: true)]
    public ?string $summary;

    #[Number(min: 1, required: true)]
    public float $readingMinutes;

    #[Boolean]
    public bool $featured;

    #[Date(time: true)]
    public CarbonImmutable $publishedAt;

    #[Select(options: ['draft' => 'Draft', 'review' => 'In review', 'live' => 'Live'], required: true)]
    public string $status;

    #[Date]
    public CarbonImmutable $reviewedOn;

    #[Select(options: ['plain' => 'Plain', 'warm' => 'Warm'])]
    public string $tone;
}
