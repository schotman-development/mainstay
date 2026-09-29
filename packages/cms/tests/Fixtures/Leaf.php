<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Text;

/* A path of one segment, which is the one that can run into a locale's
   prefix or the admin's. */
#[Route('/{slug}')]
class Leaf extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[Text(required: true, localized: true)]
    public string $slug;
}
