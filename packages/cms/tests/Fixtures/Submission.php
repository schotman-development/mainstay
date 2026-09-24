<?php

namespace Mainstay\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Mainstay\Content\Entry;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;
use Mainstay\Tests\Fixtures\Policies\FormPolicy;

/* A public form's submissions: anyone may send one, and what becomes of it
   is internal, starting from a default the sender never sees. */
#[UsePolicy(FormPolicy::class)]
class Submission extends Entry
{
    #[Text(required: true)]
    public string $name;

    #[Text]
    public ?string $message;

    #[Select(options: ['new', 'handled'], required: true)]
    #[Internal]
    public string $state = 'new';
}
