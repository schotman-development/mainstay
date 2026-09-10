@props(['align' => 'start', 'chevron' => true, 'triggerClass' => null])

{{--
 | A disclosure, not a role="menu". The ARIA menu pattern takes over the arrow
 | keys and drops its items out of the tab order, which is right for an
 | application menu bar and wrong for a short list of ordinary controls in a
 | header. <details> gives the open/closed state, the button semantics on
 | <summary> and the keyboard toggle for free; the admin's bundle adds the three
 | things it has no native answer for -- dismiss on outside click, dismiss on
 | Escape, dismiss on focus leaving -- against [data-dropdown], once for every
 | dropdown on the page rather than once per instance.
 |
 | The trigger's own classes replace the default rather than merging with it:
 | a caller handing over a height and a type size is describing a different
 | trigger, not decorating this one.
--}}
<details data-dropdown {{ $attributes->class(['relative']) }}>
    <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent [&::-webkit-details-marker]:hidden {{ $triggerClass ?: 'px-2.5 py-1.5 text-sm text-muted hover:bg-canvas hover:text-ink' }}">
        {{ $label }}

        {{-- An icon-only trigger carries its own affordance; a chevron beside it
             reads as a second one. --}}
        @if ($chevron)
            <x-mainstay::path-icon d="m4 6 4 4 4-4" class="size-3.5 opacity-70" />
        @endif
    </summary>

    {{--
     | Activating a control inside the panel dismisses it, which is why no
     | consumer has to wrap its own handlers to close the menu. The bundle
     | listens for a click that landed on a link or a button, so one rule covers
     | mouse and keyboard alike -- Enter on a focused item dispatches a bubbling
     | click -- without tearing the menu away from someone dragging to select
     | the email address in the user menu's header.
    --}}
    <div class="absolute z-20 mt-1 min-w-48 rounded-control border border-border bg-canvas py-1 shadow-lg {{ $align === 'end' ? 'right-0' : 'left-0' }}">
        {{ $slot }}
    </div>
</details>
