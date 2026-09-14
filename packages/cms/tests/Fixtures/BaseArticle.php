<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

abstract class BaseArticle extends Entry
{
    #[Text(required: true)]
    public string $title;
}
