@props(['item', 'path', 'active' => null, 'owner' => 'sidebar', 'depth' => 0])

@php
    $children = $item['items'] ?? [];
    $flyout = ($item['submenu'] ?? null) === 'flyout';

    /* A prefix of the active item's position is an ancestor of it, which is the
       whole of "am I the section being looked at". */
    $inside = $active !== null && ($active === $path || str_starts_with($active, $path.'.'));

    /*
     | The route decides whether the section is open. The reader's override is
     | applied in the browser, which is also where it is remembered: this is the
     | state the page is served in.
     |
     | A flyout is never toggled -- walking into the section opens the list in
     | the navigation, which is what withdraws the flyout once you are inside it.
     */
    $expanded = $inside;

    /*
     | The row is the page itself, or it is not. The third state -- a folded row
     | standing in for the page it is hiding -- only exists once the reader has
     | folded something, so the browser writes it: without it, closing the
     | section you are standing in leaves the only aria-current in the tree
     | behind display:none, with nothing marked on screen and nothing for a
     | screen reader to find either. data-inside is what tells the bundle this
     | row is allowed to stand in; data-current is what it puts back when the
     | row is opened again.
     */
    $mark = $active === $path ? 'page' : null;

    /*
     | The second level steps in on its own; the third takes a shorter step and
     | a rule down its left, which is what stops a collection's verbs reading as
     | more collections. Shorter because the smaller type is already doing some
     | of that work -- a full step again would staircase the panel away to the
     | right for no more legibility than this buys.
     */
    $open = $depth === 0
        ? 'mt-1 ml-3.5 flex flex-col gap-1 pl-2'
        : 'mt-1 ml-2 flex flex-col gap-1 border-l border-border pl-2';

    /*
     | The flyout sits flush against the row rather than a few pixels off it: a
     | gap belongs to neither element, so crossing it slowly drops :hover, and
     | visibility flips with no transition to wait behind -- the panel goes, and
     | the pointer is over nothing that can bring it back.
     */
    $shut = 'invisible absolute left-full top-0 z-20 flex min-w-44 flex-col gap-1 rounded-control border border-border bg-canvas p-1 shadow-lg';

    /*
     | A toggle's list carries the open classes whatever state it is in and
     | hides on the data attribute, rather than swapping one class list for
     | another. The reader's override is then one attribute for the browser to
     | flip, and both class lists stay stated once, here.
     */
    $list = $flyout
        ? ($expanded ? $open : $shut)
        : $open.' data-[open=false]:hidden';

    $panel = $owner.'-'.$path;
@endphp

{{--
 | One row of the navigation, and its sub items if it has any.
 |
 | A toggle's row is a button and a flyout's row is a link, because the two do
 | different things when clicked and only one of them goes anywhere. What they
 | share is the look, which lives in the menu item rather than in a copy here.
 |
 | Open, shut and flown out are the same <ul> wearing different classes rather
 | than a list per state: one set of links, one id for aria-controls, and no way
 | for the renderings to drift apart.
 |
 | A flyout's hover and focus are handled in CSS against the direct child list,
 | so a collection sitting inside an open Collections opens its own panel
 | without its parent's hover dragging every sibling open too. Hiding with
 | visibility rather than display is what makes the keyboard work: the links are
 | out of the tab order until the parent link takes focus, and focus-within then
 | reveals them.
--}}
@php
    /* A leaf row is a bare <li>: `relative` is only there to hang a flyout off,
       and @class would leave an empty class attribute on every link in the
       panel to say nothing. */
    $row = count($children) === 0 ? '' : ' class="relative'.($flyout ? ' [&:focus-within>ul]:visible [&:hover>ul]:visible' : '').'"';
@endphp

<li{!! $row !!}>
    @if (count($children) === 0 || $flyout)
        <x-mainstay::menu-item
            :href="$item['href']"
            :current="$active === $path"
            :small="$depth >= 2"
            class="flex flex-1 items-center gap-2"
        >
            @isset ($item['icon'])
                <span class="flex shrink-0" aria-hidden="true">{!! $item['icon'] !!}</span>
            @endisset
            {{ $item['label'] }}
        </x-mainstay::menu-item>
    @else
        <x-mainstay::menu-item
            as="button"
            type="button"
            :aria-current="$mark"
            :aria-expanded="$expanded ? 'true' : 'false'"
            :aria-controls="$panel"
            :data-inside="$inside ? 'true' : 'false'"
            :data-current="$mark"
            :small="$depth >= 2"
            data-sidebar-toggle
            {{-- The current look follows aria-current rather than a class fixed
                 at render, so the one attribute the bundle flips carries the
                 styling with it and the two cannot disagree. --}}
            class="group flex w-full items-center gap-2 aria-[current]:bg-canvas aria-[current]:font-medium aria-[current]:text-ink aria-[current]:hover:bg-canvas aria-[current]:hover:text-ink"
        >
            @isset ($item['icon'])
                <span class="flex shrink-0" aria-hidden="true">{!! $item['icon'] !!}</span>
            @endisset
            {{ $item['label'] }}

            {{-- The chevron follows the button's own aria-expanded rather than
                 a class of its own, so the browser flipping one attribute turns
                 it -- and the two cannot disagree about which way it points. --}}
            <x-mainstay::path-icon d="m6 4 4 4-4 4" class="ml-auto size-3.5 shrink-0 opacity-70 transition-transform group-aria-expanded:rotate-90" />
        </x-mainstay::menu-item>
    @endif

    @if (count($children) > 0)
        {{-- A shut toggle keeps its list in the document rather than dropping
             it, so the button's aria-controls points at something a screen
             reader can go and find.

             A toggle's list carries the open classes and hides on the data
             attribute rather than swapping class lists, so the reader's own
             override is one attribute for the browser to flip and the classes
             stay stated once, here. --}}
        <ul
            id="{{ $panel }}"
            @unless ($flyout) data-open="{{ $expanded ? 'true' : 'false' }}" @endunless
            class="{{ $list }}"
        >
            @foreach ($children as $i => $child)
                <x-mainstay::sidebar-item :item="$child" :path="$path.'.'.$i" :active="$active" :owner="$owner" :depth="$depth + 1" />
            @endforeach
        </ul>
    @endif
</li>
