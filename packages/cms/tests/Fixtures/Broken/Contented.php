<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Document;
use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Content;

/* A global declaring content, which is an entry's own. */
class Contented extends GlobalSet
{
    #[Content]
    public ?Document $content;
}
