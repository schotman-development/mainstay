<?php

namespace Mainstay\Tests\Fixtures\Drafted;

use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Text;

/* A path ending in a literal segment, after the slug. */
#[Route('/printed/{slug}/copy')]
class Printed extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[Text(required: true, localized: true)]
    public string $slug;
}
