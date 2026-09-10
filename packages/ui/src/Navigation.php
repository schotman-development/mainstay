<?php

namespace Mainstay\Ui;

class Navigation
{
    /*
     | Which item the pathname belongs to, as its position in the tree --
     | "1.0.2" being the third child of the first item of the second section.
     |
     | A position rather than the item itself, because PHP arrays compare by
     | value: two items pointing at the same route -- a pinned shortcut
     | alongside its section, or a collection beside its own "view posts" --
     | would both match an item returned by value, and mark two links instead of
     | one. It also makes "is the item I am rendering inside the active one" a
     | prefix test on a string rather than another walk of the tree.
     |
     | Prefix matching on the path, so /admin/pages/42 still marks Pages -- an
     | exact test would leave every detail page with nothing marked at all.
     | Longest wins, because a dashboard mounted at /admin is a prefix of every
     | other section and would otherwise claim all of them. A root item is the
     | one exception: "/" prefixes the whole site, so it only ever matches
     | itself.
     |
     | Ties go to the first in document order, which is how the caller controls
     | which of two equally-good matches wins.
     */
    public static function activeKey(array $sections, ?string $current): ?string
    {
        if ($current === null || $current === '') {
            return null;
        }

        $path = self::normalise($current);
        $best = null;
        $length = -1;

        foreach (self::flatten($sections) as $key => $item) {
            $href = self::normalise((string) ($item['href'] ?? ''));

            if ($path !== $href && ($href === '/' || ! str_starts_with($path, $href.'/'))) {
                continue;
            }

            if (strlen($href) > $length) {
                $length = strlen($href);
                $best = $key;
            }
        }

        return $best;
    }

    /*
     | Every item in the tree keyed by position, parents before their own
     | children. Matching has to see the whole depth: a sub item is by
     | construction a longer path than the parent it hangs off, so leaving it
     | out does not merely fail to mark it, it hands the mark to the parent
     | instead.
     */
    private static function flatten(array $sections): array
    {
        $flat = [];

        foreach ($sections as $s => $section) {
            foreach ($section['items'] ?? [] as $i => $item) {
                $flat += self::walk($item, $s.'.'.$i);
            }
        }

        return $flat;
    }

    private static function walk(array $item, string $key): array
    {
        $flat = [$key => $item];

        foreach ($item['items'] ?? [] as $i => $child) {
            $flat += self::walk($child, $key.'.'.$i);
        }

        return $flat;
    }

    /*
     | Query strings and hashes are not part of the route. Without stripping
     | them a caller who hands over the full URL instead of the path fails both
     | tests above, and the match falls through to a shallower item -- a wrong
     | section marked, which reads worse than none.
     |
     | Repeated slashes are collapsed and trailing ones dropped, on both sides,
     | so an href and a path that disagree about them still match -- a server
     | will serve /admin//pages quite happily. Everything collapses to "/"
     | rather than "", which would otherwise prefix-match every path in
     | existence.
     |
     | Stripping the hash means an href that routes through one (/admin#/pages)
     | is not supported; every item would flatten to /admin and tie.
     */
    private static function normalise(string $path): string
    {
        $path = rtrim(preg_replace(['/[?#].*$/', '#/{2,}#'], ['', '/'], $path), '/');

        return $path === '' ? '/' : $path;
    }
}
