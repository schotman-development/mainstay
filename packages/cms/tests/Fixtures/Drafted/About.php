<?php

namespace Mainstay\Tests\Fixtures\Drafted;

use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Text;

/* A path with no field in it at all. */
#[Route('/about')]
class About extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;
}
