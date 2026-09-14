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

/* Every field type twice, once able to hold nothing and once not, because an
   empty box means two different things depending on which it is. */
class Blanks extends Entry
{
    #[Text]
    public string $keptText;

    #[Text]
    public ?string $absentText;

    #[Textarea]
    public string $keptProse;

    #[Textarea]
    public ?string $absentProse;

    #[Number]
    public int $keptNumber;

    #[Number]
    public ?int $absentNumber;

    #[Boolean]
    public bool $keptFlag;

    #[Boolean]
    public ?bool $absentFlag;

    #[Select(options: ['a', 'b'])]
    public string $keptChoice;

    #[Select(options: ['a', 'b'])]
    public ?string $absentChoice;

    #[Date]
    public CarbonImmutable $neverToday;

    #[Date]
    public ?CarbonImmutable $absentDate;
}
