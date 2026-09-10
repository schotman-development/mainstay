<?php

namespace Mainstay\Ui;

use Collator;
use Normalizer;

class EntryList
{
    /*
     | Search, filter, sort and page, in one pass and with no state of its own,
     | so the interesting part of a list screen is testable without rendering it.
     |
     | The page is clamped rather than trusted: narrowing the query shortens the
     | result, and the page the caller is holding can easily be past the end of
     | what is left. Returning the clamped value is what lets the footer stay
     | honest. It arrives off a query string here, so "trusted" would mean
     | trusting whatever someone typed into the address bar.
     |
     | Returns the rows on screen and everything the search matched: the bulk
     | bar acts on the selection, so the selection it reports has to be what the
     | search left behind -- otherwise "Move to trash" reaches rows that are not
     | on screen and cannot be checked before the click.
     */
    public static function shape(array $entries, array $view): array
    {
        $needle = self::fold(trim((string) ($view['query'] ?? '')));
        $status = $view['status'] ?? 'All';

        $matched = array_values(array_filter($entries, function (array $entry) use ($needle, $status) {
            if ($status !== 'All' && $entry['status'] !== $status) {
                return false;
            }

            return $needle === ''
                || str_contains(self::fold($entry['title']), $needle)
                || str_contains(self::fold($entry['author']), $needle)
                || str_contains(self::fold($entry['category']), $needle);
        }));

        $field = $view['field'] ?? 'modified';
        $direction = $view['direction'] ?? 'desc';

        /* usort is not stable before PHP 8.0 and is guaranteed from 8.0 on, so
           ties keep author order -- which is how a caller controls tie-breaks. */
        $sorted = $matched;
        usort($sorted, fn (array $a, array $b) => self::compare($a, $b, $field, $direction));

        $total = count($sorted);

        /* ?: catches a non-numeric size as well as zero, and the floor keeps a
           fractional page from stranding rows between two pages that both claim
           to be whole. */
        $size = (float) ($view['perPage'] ?? 8);
        $perPage = max(1, (is_finite($size) ? (int) floor($size) : 0) ?: 1);

        $pageCount = max(1, (int) ceil($total / $perPage));

        $wanted = (float) ($view['page'] ?? 1);
        $page = min(max((is_finite($wanted) ? (int) floor($wanted) : 0) ?: 1, 1), $pageCount);
        $start = ($page - 1) * $perPage;

        return [
            'rows' => array_slice($sorted, $start, $perPage),
            'matched' => $sorted,
            'total' => $total,
            'page' => $page,
            'pageCount' => $pageCount,
            'start' => $start,
        ];
    }

    /*
     | What the header checkbox should show for the rows currently on screen.
     |
     | Selection outlives paging, so "all" means every row you can see, not every
     | row that exists -- otherwise the box never fills in on a paged list and
     | there is no way to tell whether ticking it will add or remove.
     */
    public static function selectionState(array $rows, array $selected): array
    {
        $count = count(array_filter($rows, fn (array $row) => in_array($row['path'], $selected, true)));

        return [
            'count' => $count,
            'all' => count($rows) > 0 && $count === count($rows),
            'some' => $count > 0 && $count < count($rows),
        ];
    }

    /*
     | NFC on both sides: an accented title stored decomposed does not match the
     | same word typed precomposed otherwise. ext-intl is used when the host has
     | it rather than required: without it the fold is still case-insensitive,
     | and only the two-forms-of-one-accent case degrades.
     */
    private static function fold(string $value): string
    {
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        }

        return mb_strtolower($value);
    }

    private static function compare(array $a, array $b, string $field, string $direction): int
    {
        $left = $a[$field] ?? null;
        $right = $b[$field] ?? null;

        /* A draft has no release date. It sorts last whichever way the column
           points, rather than leading the list every time you sort by it. */
        if ($left === null || $right === null) {
            return $left === $right ? 0 : ($left === null ? 1 : -1);
        }

        /* Collated when the host has ext-intl, so "Ångström" files where a
           reader expects rather than after "Z". strcmp is the fallback and
           agrees with it across the ASCII the admin is mostly made of. */
        static $collator = null;
        $collator ??= class_exists(Collator::class) ? new Collator('en') : false;

        $order = $collator ? $collator->compare($left, $right) : strcmp($left, $right);

        return $direction === 'asc' ? $order : -$order;
    }
}
