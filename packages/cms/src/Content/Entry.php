<?php

namespace Mainstay\Content;

use Closure;
use Mainstay\Mainstay;
use ReflectionClass;
use ReflectionProperty;

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

    /*
     | Whether this stands for an entry a relation points at and the read did
     | not load: depth ran out, or the entry is trashed, gone, untranslated in
     | the locale read, or of a type the reader may not read. Such a one holds
     | its id and nothing else -- every field absent, no path -- so a list
     | written back as it was read keeps it, as an image that is away is kept.
     |
     | Public, as an image's is, so a template asks `$related->missing`. A
     | type redeclaring it as a bool is refused by sync; as anything else, it
     | fails to load with PHP's message about types.
     */
    public bool $missing = false;

    /* Where the entry is linked to: its locale's base and its path. Null for
       a type with no #[Route], and for an entry not read with its path. */
    public function url(): ?string
    {
        return app(Mainstay::class)->url($this);
    }

    /* What a relation holds for the entry `$id` of this type when the read
       did not load it: see $missing. */
    public static function reference(int $id): static
    {
        $entry = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

        /* A field's declared default is a value nobody stored, so it goes, as
           it does from an entry a read hands out. A hooked property cannot be
           unset and keeps its default -- declared, not stored. */
        foreach (array_keys(app(Mainstay::class)->fields(static::class)) as $name) {
            $property = new ReflectionProperty(static::class, $name);

            if ($property->isInitialized($entry) && ! (method_exists($property, 'hasHooks') && $property->hasHooks())) {
                Closure::bind(function () use ($name) {
                    unset($this->{$name});
                }, $entry, $property->class)();
            }
        }

        $entry->id = $id;
        $entry->missing = true;

        return $entry;
    }
}
