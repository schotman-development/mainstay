<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Content\Template;
use Mainstay\Fields\Text;

/* A view named that nobody wrote. */
#[Route('/lost')]
#[Template('nowhere')]
class Lost extends Entry
{
    #[Text]
    public ?string $title;
}
