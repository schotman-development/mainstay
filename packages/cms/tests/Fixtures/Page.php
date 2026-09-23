<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;

/* A path built from a shared field and a translated one, so changing the
   shared one moves every locale's path at once. */
#[Route('/{section}/{slug}')]
class Page extends Entry
{
    #[Select(options: ['about', 'work'], required: true)]
    public string $section;

    #[Text(required: true, localized: true)]
    public string $slug;
}
