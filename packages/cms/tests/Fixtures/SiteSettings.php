<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Text;

class SiteSettings extends GlobalSet
{
    #[Text(required: true)]
    public string $siteName;
}
