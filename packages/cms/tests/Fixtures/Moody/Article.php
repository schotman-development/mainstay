<?php

namespace Mainstay\Tests\Fixtures\Moody;

use Mainstay\Tests\Fixtures\Article as BaseArticle;
use Mainstay\Tests\Fixtures\RawColumn;

/* Tiered\Article's enum while it was still optional. */
class Article extends BaseArticle
{
    #[RawColumn(['enum', ['calm', 'loud']])]
    public ?string $mood;
}
