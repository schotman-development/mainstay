<?php

namespace Mainstay\Tests\Fixtures\Recoded;

use Mainstay\Content\Entry;
use Mainstay\Fields\Number;

/* Coded\Article after its code became a number: a type change the stored
   values have to be cast across. */
class Article extends Entry
{
    #[Number]
    public ?int $code;
}
