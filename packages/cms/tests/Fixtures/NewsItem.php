<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Fields\Select;

/* Fields reaching a type three ways: a base class, a trait and its own body. */
class NewsItem extends BaseArticle
{
    use HasSeo;

    #[Select(options: ['wire', 'staff'])]
    public ?string $source;
}
