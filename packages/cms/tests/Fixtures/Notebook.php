<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

class Notebook extends Entry
{
    #[Text(required: true)]
    public string $title;

    #[JsonNote]
    public ?array $jottings;
}
