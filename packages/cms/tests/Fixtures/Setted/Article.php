<?php

namespace Mainstay\Tests\Fixtures\Setted;

use Mainstay\Tests\Fixtures\Article as BaseArticle;
use Mainstay\Tests\Fixtures\RawColumn;

/* A required set column, which only MySQL has. */
class Article extends BaseArticle
{
    #[RawColumn(['set', ['news', 'opinion']])]
    public string $sections;
}
