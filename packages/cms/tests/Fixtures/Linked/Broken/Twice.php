<?php

namespace Mainstay\Tests\Fixtures\Linked\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Terms;
use Mainstay\Tests\Fixtures\Linked\Genre;

class Twice extends Entry
{
    #[Terms(of: Genre::class)]
    public array $genres;

    #[Terms(of: Genre::class)]
    public array $featured;
}
