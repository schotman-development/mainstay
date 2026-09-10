<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Fields\Textarea;

trait HasSeo
{
    #[Textarea]
    public ?string $seoDescription;
}
