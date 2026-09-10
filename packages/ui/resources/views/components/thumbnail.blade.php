@props(['src' => null, 'size' => 'sm', 'fallback', 'dashed' => false])

@php
    /*
     | The three sizes the admin actually uses: a table row, a form field, and
     | the content card. Named here rather than taken as a class, so a fourth
     | size has to be added and named -- which is the only thing stopping a
     | design system from quietly growing one size per call site.
     |
     | shrink-0 unconditionally: two of the three sit in a flex row, and a
     | thumbnail squeezed narrower than its own aspect ratio is worse than one
     | that pushes the text.
     */
    $box = match ($size) {
        'md' => 'h-14 w-20',
        'lg' => 'h-16 w-24',
        default => 'h-8 w-12',
    }.' shrink-0 rounded-control border border-border';
@endphp

{{--
 | A rendered preview, or a same-sized box when there is not one. Both states
 | are the same size on purpose: a thumbnail that appears when you choose an
 | image, in a space that was not already holding it, shifts everything below.
 |
 | Always decorative. Every call site has the title next to it, and an image
 | described twice is an image announced twice.
--}}
@if ($src)
    <img src="{{ $src }}" alt="" class="{{ $box }} object-cover">
@else
    {{--
     | Dashed reads as a slot waiting to be filled; solid reads as a stand-in
     | for the thing itself. A list beside real previews wants solid -- a dashed
     | tile in a column of solid ones looks like the column failed to load
     | rather than like the page has no preview.
     |
     | font-medium is what makes a lone initial read as a glyph rather than as a
     | stray letter. A word does not need it, and setting it there just makes
     | "Empty" louder than the thing it is standing in for.
    --}}
    <div aria-hidden="true" class="{{ $box }} grid place-content-center text-xs text-muted {{ $dashed ? 'border-dashed' : 'bg-canvas font-medium' }}">{{ $fallback }}</div>
@endif
