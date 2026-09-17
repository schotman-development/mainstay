<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

/* Stored as site_id, which every content table already has. */
class Reserved extends Entry
{
    #[Text]
    public string $siteId;
}
