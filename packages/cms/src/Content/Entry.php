<?php

namespace Mainstay\Content;

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
}
