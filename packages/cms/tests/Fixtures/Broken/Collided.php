<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

/* Two properties, one column: both are stored as sub_title. */
class Collided extends Entry
{
    #[Text]
    public string $subTitle;

    #[Text]
    public string $sub_title;
}
