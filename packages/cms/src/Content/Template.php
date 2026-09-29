<?php

namespace Mainstay\Content;

use Attribute;

/*
 | The views a type's entries render with, where its handle is not the name:
 | `#[Template('docs.page')]`. The first is the one they render with; any
 | after it an entry may name as its own instead, `#[Template('docs.page',
 | 'docs.wide')]`, and no other, since a view is written against the type's
 | fields.
 |
 | Not inherited, as #[Route] is not: a subclass is its own type, and takes
 | its own handle as its view unless it says otherwise.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Template
{
    /** @var list<string> */
    public readonly array $views;

    public function __construct(string $view, string ...$others)
    {
        $this->views = [$view, ...$others];
    }
}
