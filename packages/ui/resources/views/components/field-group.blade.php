@props(['label'])

{{--
 | A card of related fields. The heading is a real <h2> rather than a styled
 | div, so a form has an outline a screen reader can jump through instead of one
 | long undifferentiated list of inputs.
--}}
<section class="rounded-control border border-border bg-surface">
    <h2 class="border-b border-border px-4 py-2.5 text-xs font-medium text-muted">{{ $label }}</h2>
    {{-- The last row's rule would double with the card's own bottom edge. --}}
    <div class="[&>*:last-child]:border-b-0">{{ $slot }}</div>
</section>
