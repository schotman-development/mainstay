<?php

namespace Mainstay\Tests\Fixtures\Linked\Broken;

use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Terms;
use Mainstay\Tests\Fixtures\Linked\Genre;

class Filed extends GlobalSet
{
    #[Terms(of: Genre::class)]
    public array $genres;
}
