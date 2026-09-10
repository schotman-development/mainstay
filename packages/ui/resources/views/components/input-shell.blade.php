{{--
 | A bordered box that behaves like one control while holding several things: a
 | prefix and a field, or a row of chips and the field that adds to them.
 |
 | focus-within rather than focus is the whole point -- the ring belongs to the
 | group, so focusing the input inside lights the box the user thinks they are
 | typing in rather than a smaller box inside it.
--}}
<div {{ $attributes->class([
    'rounded-control border border-border bg-canvas',
    'focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-accent',
]) }}>{{ $slot }}</div>
