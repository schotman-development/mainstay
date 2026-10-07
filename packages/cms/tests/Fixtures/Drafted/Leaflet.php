<?php

namespace Mainstay\Tests\Fixtures\Drafted;

use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Text;

/* A path at the root, which a locale's prefix can come to answer. */
#[Route('/{slug}')]
class Leaflet extends Entry
{
    #[Text(required: true, localized: true)]
    public string $slug;
}
