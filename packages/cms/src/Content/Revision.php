<?php

namespace Mainstay\Content;

use Carbon\CarbonImmutable;

/* An earlier version of an entry, listed without its content: what
   restoring one brings back is a draft. `createdAt` is when it stopped
   being live. */
final class Revision
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $entryId,
        public readonly CarbonImmutable $createdAt,
        /* Who had published this version, none for code on its own
           authority. */
        public readonly ?int $publishedBy = null,
    ) {}
}
