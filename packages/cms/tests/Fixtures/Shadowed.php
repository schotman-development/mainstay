<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Text;

/* Every path under `/nl`, which beside a Dutch served there no English one
   can have. */
#[Route('/nl/{slug}')]
class Shadowed extends Entry
{
    #[Text(required: true)]
    public string $slug;
}
