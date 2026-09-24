<?php

namespace Mainstay\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Mainstay\Content\Entry;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;
use Mainstay\Tests\Fixtures\Policies\FormPolicy;

/* The same form with no default for what the sender cannot see. */
#[UsePolicy(FormPolicy::class)]
class Ticket extends Entry
{
    #[Text(required: true)]
    public string $name;

    #[Select(options: ['new', 'handled'], required: true)]
    #[Internal]
    public string $state;
}
