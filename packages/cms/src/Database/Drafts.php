<?php

namespace Mainstay\Database;

use Illuminate\Support\Collection;
use Mainstay\Content\ContentType;
use Mainstay\Content\Draft;

/*
 | What editors are working on, beside what the site shows:
 | `Mainstay::drafts()->save(Article::class, [...], entry: 5)`. The calls are
 | the query layer's, since a draft is checked, read and put live through the
 | same rules, paths and Gate as every write.
 */
class Drafts
{
    public function __construct(private ContentStore $store) {}

    public function save(string $type, array $data, ?int $entry = null, ?int $draft = null, ?string $locale = null, bool $overrideAccess = false): ?Draft
    {
        return $this->store->saveDraft($type, $data, $entry, $draft, $locale, $overrideAccess);
    }

    public function find(int $id, ?string $locale = null, bool $overrideAccess = false, int $depth = 1): ?Draft
    {
        return $this->store->findDraft($id, $locale, $overrideAccess, $depth);
    }

    public function of(string $type, ?int $entry = null, ?string $locale = null, bool $overrideAccess = false, int $depth = 1): ?Draft
    {
        return $this->store->draftOf($type, $entry, $locale, $overrideAccess, $depth);
    }

    /** @return Collection<int, Draft> */
    public function all(string $type, ?string $locale = null, bool $overrideAccess = false, int $depth = 1): Collection
    {
        return $this->store->drafts($type, $locale, $overrideAccess, $depth);
    }

    public function publish(int $id, bool $overrideAccess = false): ContentType
    {
        return $this->store->publish($id, $overrideAccess);
    }

    public function discard(int $id, bool $overrideAccess = false): void
    {
        $this->store->discardDraft($id, $overrideAccess);
    }
}
