@props(['filled' => false])

{{--
 | The svg preamble, once. Every icon in the admin is a 16-unit box drawn in the
 | current colour, and the six copies of these attributes that used to exist
 | were six chances for one icon to be a different weight from the rest.
 |
 | Always aria-hidden: an icon here is either decorative or sits beside a label
 | that already says the thing. Anything that needs a name gets an sr-only span
 | from whatever wraps it.
 |
 | The class replaces the default rather than merging with it. Callers hand over
 | a size -- size-3.5, size-4 -- and two size utilities on one element is decided
 | by the stylesheet's order rather than by the caller.
--}}
<svg aria-hidden="true" viewBox="0 0 16 16" class="{{ $attributes->get('class') ?: 'size-4' }}" fill="{{ $filled ? 'currentColor' : 'none' }}"@unless ($filled) stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"@endunless>{{ $slot }}</svg>
