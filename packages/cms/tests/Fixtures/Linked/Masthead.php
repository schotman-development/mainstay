<?php

namespace Mainstay\Tests\Fixtures\Linked;

use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Relation;
use Mainstay\Fields\Text;

/* A global with a menu of its own in each language. */
class Masthead extends GlobalSet
{
    #[Text(required: true, localized: true)]
    public string $motto;

    #[Relation(to: Story::class, localized: true)]
    public array $menu;

    #[Internal]
    #[Text]
    public ?string $secret;
}
