@props(['danger' => false, 'as' => 'button'])

{{--
 | danger is a prop rather than something a caller passes as a class, because a
 | text-danger handed over that way loses: it and the text-ink below are both
 | single classes, so the stylesheet order decides and text-ink is emitted last.
 | The destructive item rendered in ordinary ink.
 |
 | `as` is for an item that goes somewhere rather than doing something -- a
 | status filter that is a link is a filter you can open in a new tab, and one
 | the browser can restore on back. The dismiss-on-activate rule in the bundle
 | already covers both, because it asks for a link or a button.
--}}
<{{ $as }} @if ($as === 'button') type="button" @endif {{ $attributes->class([
    'block w-full px-3 py-1.5 text-left text-sm hover:bg-surface',
    'text-danger' => $danger,
    'text-ink' => ! $danger,
    'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent',
]) }}>{{ $slot }}</{{ $as }}>
