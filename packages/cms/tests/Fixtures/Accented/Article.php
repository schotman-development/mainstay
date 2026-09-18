<?php

namespace Mainstay\Tests\Fixtures\Accented;

use Mainstay\Tests\Fixtures\Article as BaseArticle;
use Mainstay\Tests\Fixtures\ColorPicker;

/* The fixture Article with a required host field added, of a type that has no
   empty value and does not say what to backfill with. */
class Article extends BaseArticle
{
    #[ColorPicker]
    public string $accent;
}
