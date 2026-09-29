<?php

namespace Mainstay\Content;

use Mainstay\Mainstay;

/*
 | Many instances, each with a route. Everything that separates an entry from a
 | global -- the slug, the URI row, the route pattern -- arrives in the phase
 | that needs it. The shape is what phase 1 has to settle.
 */
abstract class Entry extends ContentType
{
    /*
     | The path the entry answers to in the locale it was read in, from the
     | lookup row rather than the pattern -- restoring from the trash can take
     | a path the pattern would not give. No locale base on it: a link is the
     | locale's base and this. Null for a type with no #[Route].
     */
    public ?string $uri;

    /*
     | The view this entry renders with, ahead of its type's #[Template] and
     | its handle, read through Mainstay::template(). Null for one that
     | renders as its type does.
     |
     | Private, since `template` was a field's name before it was Mainstay's.
     | A subclass redeclaring a public property has to keep its type or fail
     | to load, with a message about types; a private one is the subclass's
     | to redeclare, and sync then refuses the field by name.
     */
    private ?string $template = null;

    /* Where the entry is linked to: its locale's base and its path. Null for
       a type with no #[Route]. */
    public function url(): ?string
    {
        return app(Mainstay::class)->url($this);
    }
}
