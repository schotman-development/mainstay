<?php

namespace Mainstay\Database;

use Illuminate\Support\Collection;
use Mainstay\Content\Draft;
use Mainstay\Content\Revision;

/*
 | What was live before something replaced it:
 | `Mainstay::revisions()->of(Article::class, 5)`. Restoring one makes it the
 | entry's draft, which leaves through publish like any other.
 */
class Revisions
{
    public function __construct(private ContentStore $store) {}

    /** @return Collection<int, Revision> */
    public function of(string $type, ?int $entry = null, bool $overrideAccess = false): Collection
    {
        return $this->store->revisionsOf($type, $entry, $overrideAccess);
    }

    public function restore(int $id, bool $overrideAccess = false): ?Draft
    {
        return $this->store->restoreRevision($id, $overrideAccess);
    }
}
