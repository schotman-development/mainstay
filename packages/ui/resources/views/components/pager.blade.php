@props(['start', 'shown', 'total', 'page', 'pageCount', 'href'])

{{--
 | A list's footer: which rows these are, and the steps either side. `href`
 | turns a page number into its link, so the list keeps whatever else its
 | query string holds. A step that goes nowhere is a <span> rather than a link
 | with a dead href: a link that goes nowhere is still in the tab order
 | announcing itself.
--}}
@php($look = 'rounded-control border border-border px-2 py-0.5 text-ink hover:bg-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent')

<div {{ $attributes->class('flex shrink-0 items-center justify-between gap-2 border-t border-border px-5 py-3 text-xs text-muted') }}>
    <span aria-live="polite">{{ $total === 0 ? 'No results' : ($start + 1).'–'.($start + $shown).' of '.$total }}</span>

    <div class="flex items-center gap-2">
        @foreach ([['Previous page', $page - 1, $page === 1, '&lsaquo;'], ['Next page', $page + 1, $page === $pageCount, '&rsaquo;']] as [$label, $to, $disabled, $glyph])
            @if ($disabled)
                <span aria-label="{{ $label }}" aria-disabled="true" class="{{ $look }} pointer-events-none opacity-40">{!! $glyph !!}</span>
            @else
                <a href="{{ $href($to) }}" aria-label="{{ $label }}" class="{{ $look }}">{!! $glyph !!}</a>
            @endif

            @if ($loop->first)
                <span>{{ $page }} of {{ $pageCount }}</span>
            @endif
        @endforeach
    </div>
</div>
