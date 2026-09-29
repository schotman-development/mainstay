<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Text;

/* A global, which renders nothing of its own, so `template` is its field's. */
class SiteSettings extends GlobalSet
{
    #[Text(required: true)]
    public string $siteName;

    #[Text]
    public ?string $template;
}
