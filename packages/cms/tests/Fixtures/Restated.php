<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Fields\Internal;

/* A child widening a base's field and marking it internal as it does, without
   restating the field attribute the base already gave it. */
class Restated extends BaseNote
{
    #[Internal]
    public string $body;

    public string $heading;
}
