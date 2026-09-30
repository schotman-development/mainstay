<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Carbon\CarbonImmutable;
use Mainstay\Content\Block;
use Mainstay\Content\Document;
use Mainstay\Fields\Date;
use Mainstay\Fields\RichText;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;

/* A block of every kind of field it can hold beside another block list: a
   required one, a document, a date, and a select with a default. */
class Callout extends Block
{
    #[Text(required: true)]
    public string $heading;

    #[RichText]
    public ?Document $text;

    #[Date]
    public ?CarbonImmutable $on;

    #[Select(options: ['info', 'warning'])]
    public string $tone = 'info';
}
