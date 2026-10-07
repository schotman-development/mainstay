<?php

namespace Mainstay\Content;

use Carbon\CarbonImmutable;

/*
 | A draft as read: what is live in the locale read with the draft's changes
 | over it, as the declared class, and which fields it changes. An entry not
 | published yet has no id and no path on it, and a field the draft leaves
 | empty that its property cannot hold is absent rather than invented.
 */
final class Draft
{
    /**
     * @param  list<string>  $fields  the shared fields it changes
     * @param  array<string, list<string>>  $locales  by locale, the localized fields it changes there
     * @param  array{fields?: array<string, string>, locales?: array<string, array<string, string>>}  $unrestored  what a restored revision could not bring back, and why
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $entryId,
        public readonly ContentType $entry,
        public readonly array $fields,
        public readonly array $locales,
        public readonly CarbonImmutable $updatedAt,
        public readonly array $unrestored = [],
    ) {}
}
