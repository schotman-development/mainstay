@props(['href', 'disabled' => false, 'label'])

{{-- A pager step. Disabled is an <span> rather than a link with a dead href:
     there is nowhere to go, and a link that goes nowhere is still in the tab
     order announcing itself. --}}
@php($look = 'rounded-control border border-border px-2 py-0.5 text-ink hover:bg-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent')

@if ($disabled)
    <span aria-label="{{ $label }}" aria-disabled="true" class="{{ $look }} pointer-events-none opacity-40">{{ $slot }}</span>
@else
    <a href="{{ $href }}" aria-label="{{ $label }}" class="{{ $look }}">{{ $slot }}</a>
@endif
