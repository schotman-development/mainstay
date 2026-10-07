<?php

namespace Mainstay\Content;

use Carbon\CarbonImmutable;

/* An earlier version of an entry, listed without its content: what
   restoring one brings back is a draft. */
final class Revision
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $entryId,
        public readonly CarbonImmutable $createdAt,
    ) {}
}
