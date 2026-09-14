<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Fields\Textarea;

/* A child widening what a base declared. PHP allows it, so the declaration an
   entry holds is this one and not the base's -- with the attribute repeated on
   one property and left to the base on the other. */
class Note extends BaseNote
{
    #[Textarea]
    public string $body;

    public string $heading;
}
