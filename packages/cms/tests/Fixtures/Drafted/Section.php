<?php

namespace Mainstay\Tests\Fixtures\Drafted;

use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;

/* A path ending in a select, whose options a suffix would leave. */
#[Route('/sections/{kind}')]
class Section extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[Select(options: ['news', 'work'], required: true)]
    public string $kind;
}
