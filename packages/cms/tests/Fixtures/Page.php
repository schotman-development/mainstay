<?php

namespace Mainstay\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;
use Mainstay\Tests\Fixtures\Policies\ClosedPolicy;

/* A path built from a shared field and a translated one, so changing the
   shared one moves every locale's path at once. Its policy is the host's
   choice, which Mainstay's own does not replace. A translated field with a
   default, for a translation's first row to take. */
#[Route('/{section}/{slug}')]
#[UsePolicy(ClosedPolicy::class)]
class Page extends Entry
{
    #[Select(options: ['about', 'work'], required: true)]
    public string $section;

    #[Text(required: true, localized: true)]
    public string $slug;

    #[Text(localized: true)]
    public string $tagline = 'Welcome';
}
