@props(['current' => false, 'compact' => false, 'small' => false, 'as' => 'a'])

{{--
 | A top bar link. Anchors rather than buttons so middle-click, cmd-click and
 | "copy link address" keep working -- an admin panel that breaks opening a
 | section in a new tab is a worse admin panel.
 |
 | aria-current is what marks the active section for a screen reader; the
 | styling is keyed off the same prop so the two cannot drift apart. A caller
 | that sets its own wins: the sidebar's folded parent marks itself "true"
 | rather than "page", because the row stands in for a page it is hiding rather
 | than being it.
 |
 | `as` is how a row that cannot be an anchor still wears the look -- a sidebar
 | parent whose whole row is the disclosure has to be a button. It replaces the
 | exported class list the React component needed for the same reason, so there
 | is still exactly one copy of these classes.
 |
 | compact, small and current are props rather than classes a caller passes in:
 | a size or a colour handed over that way is a single class competing with a
 | single class, and the winner is whichever the stylesheet emits last rather
 | than whichever the caller wrote.
--}}
<{{ $as }}@if ($current && ! $attributes->has('aria-current')) aria-current="page"@endif {{ $attributes->class([
        'rounded-control transition-colors',
        'inline-flex h-6 items-center px-2.5 text-xs' => $compact,
        'px-2 py-1.5 text-sm' => ! $compact && ! $small,
        'px-2 py-1.5 text-xs' => ! $compact && $small,
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
        'bg-canvas font-medium text-ink' => $current,
        'text-muted hover:bg-canvas hover:text-ink' => ! $current,
    ]) }}>{{ $slot }}</{{ $as }}>
