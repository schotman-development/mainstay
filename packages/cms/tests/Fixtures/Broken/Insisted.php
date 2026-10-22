<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Blocks;
use Mainstay\Tests\Fixtures\Blocks\Slide;

/* Blocks named on the class with a field's argument, which a content's list
   does not take. */
#[Route('/insisted/{title}')]
#[Blocks([Slide::class], required: true)]
class Insisted extends Entry {}
