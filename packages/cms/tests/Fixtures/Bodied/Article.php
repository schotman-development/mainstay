<?php

namespace Mainstay\Tests\Fixtures\Bodied;

use Mainstay\Content\Document;
use Mainstay\Fields\RichText;
use Mainstay\Tests\Fixtures\Article as BaseArticle;

/* The fixture Article with a body added, which cannot be null. */
class Article extends BaseArticle
{
    #[RichText]
    public Document $body;
}
