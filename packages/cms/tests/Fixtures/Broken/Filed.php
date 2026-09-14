<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Tests\Fixtures\BaseFiled;

/* Not the base's property widened: a private declaration and a child property
   of the same name are two slots, and the field is on the one nothing outside
   the base can write. */
class Filed extends BaseFiled
{
    public string $filed;
}
