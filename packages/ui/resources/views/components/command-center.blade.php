@props(['commands' => [], 'placeholder' => 'Search or jump to…', 'id' => 'command-center'])

@php
    /* The panel behind both states, so "No matches" and a list of results sit
       in exactly the same box. */
    $panel = 'absolute z-20 mt-1 w-full rounded-control border border-border bg-canvas shadow-lg';
@endphp

{{--
 | Navigation and quick actions behind one input. The list opens on focus with
 | every command showing, so it reads as a menu before it is used as a search.
 |
 | The commands are data, ranked in the browser by the same rankCommands the
 | React version used -- the one piece of this component that was never about
 | rendering and ported unchanged, tests and all. A command's `href` is where
 | its `run` callback went: a server-rendered admin navigates.
 |
 | Both states of the panel are rendered here and hidden, and one row is written
 | once into a <template>, so the bundle chooses and fills rather than deciding
 | what a result looks like.
--}}
<div data-command-center {{ $attributes->class(['relative']) }}>
    <x-mainstay::icon class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted">
        <circle cx="7" cy="7" r="4.5" />
        <path d="m10.5 10.5 4 4" />
    </x-mainstay::icon>

    {{-- Its own room for the icon and the shortcut hint, but the border, the
         ground and the focus ring come from the same place as every other
         control's. --}}
    <x-mainstay::input
        size="none"
        type="text"
        role="combobox"
        aria-expanded="false"
        aria-autocomplete="list"
        aria-label="Search or jump to"
        autocomplete="off"
        data-command-field
        :placeholder="$placeholder"
        class="h-6.5 pl-8 pr-12 text-xs text-ink placeholder:text-muted"
    />

    {{--
     | Sized rather than padded: a fixed 18px box inside the 26px field leaves
     | 4px of air either side whatever the type does.
     |
     | Centred by matching the line height to that box rather than by flex.
     | align-items centres the line box, and at line-height 1 the box is 11px
     | while the glyphs need about 13 -- so they hang out of it, unevenly, and
     | land low. Giving the line box the box's own height lets the browser split
     | the leading either side of the glyphs, which is what centres them.
     |
     | Ctrl on the server because it cannot know whose keyboard this is; the
     | bundle swaps it on a Mac. Cosmetic only -- the handler accepts either
     | modifier regardless, so a wrong guess costs a misleading badge rather
     | than a broken shortcut.
    --}}
    <kbd data-command-shortcut class="pointer-events-none absolute right-1.5 top-1/2 h-4.5 -translate-y-1/2 rounded-control border border-border px-1.5 text-center font-sans text-[0.6875rem] leading-4.5 text-muted">Ctrl K</kbd>

    <p data-command-empty hidden class="{{ $panel }} px-3 py-2 text-sm text-muted">No matches</p>

    <ul data-command-list id="{{ $id }}-list" role="listbox" hidden class="{{ $panel }} max-h-80 overflow-y-auto py-1"></ul>

    <template data-command-option>
        <li role="option" class="group flex cursor-pointer items-center justify-between gap-4 px-3 py-1.5 text-sm text-ink aria-selected:bg-accent aria-selected:text-accent-ink">
            <span data-command-label class="truncate"></span>
            {{-- Not dimmed on the active row: the foreground at 80% over the
                 highlight is 3.76:1, under AA, and this is the row a user
                 reads. --}}
            <span data-command-group class="text-xs text-muted group-aria-selected:text-inherit" hidden></span>
        </li>
    </template>

    <script type="application/json" data-commands>@json(array_values($commands))</script>
</div>
