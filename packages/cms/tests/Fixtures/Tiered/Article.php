<?php

namespace Mainstay\Tests\Fixtures\Tiered;

use Mainstay\Tests\Fixtures\Article as BaseArticle;
use Mainstay\Tests\Fixtures\RawColumn;

/* The fixture Article with required host fields whose columns only the
   column type can fill: an enum and a year. */
class Article extends BaseArticle
{
    #[RawColumn(['enum', ['calm', 'loud']])]
    public string $mood;

    #[RawColumn(['year'])]
    public int $vintage;
}
