<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Fields\Internal;
use Mainstay\Fields\Text;

/* A base's internal field widened to public by a child that restates neither
   attribute, which is the declaration that publishes it if the flag is read
   off the child alone. */
class Memo extends BaseMemo
{
    public string $note;

    #[Text]
    public string $title;

    #[Text]
    #[Internal]
    public ?string $source;
}
