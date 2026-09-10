@props(['current', 'breadcrumb' => [], 'actions' => null])

{{--
 | The admin, minus whatever screen you happen to be on. The bar and the
 | navigation are fixed: every route in the panel renders inside this, and no
 | screen brings chrome of its own. A screen that assembles its own bar and
 | navigation is a second layout to keep in step, and it will not be kept in
 | step.
 |
 | What it hands a screen is a box with a definite height and nothing else: no
 | width cap, no padding, no card. Filling that box rather than growing to fit
 | is the screen's half of the bargain -- the page list's `fill` is exactly this
 | contract, and it is what keeps the bar and the navigation still while the
 | content scrolls under them.
--}}
<div class="flex h-full flex-col bg-canvas font-sans text-ink">
    <x-stories::top-bar />

    {{-- min-h-0 is what lets the main column scroll: without it a flex child
         takes its content height as a floor and the whole page scrolls instead,
         carrying the bar off the top of the screen. --}}
    <div class="flex min-h-0 flex-1">
        <x-stories::demo-sidebar :current="$current" />

        <main class="flex min-w-0 flex-1 flex-col overflow-hidden bg-canvas">
            {{-- Above the content and inside the main column, so it lines up
                 with the screen rather than with the navigation, and so it
                 stays put while the content scrolls under it. --}}
            <div class="flex shrink-0 flex-wrap items-center gap-3 border-b border-border px-5 py-3">
                {{-- Where you are, deepest last. Part of the shell rather than
                     of the screen: a trail that only some routes bother to draw
                     is a trail you cannot learn to rely on. No href is what
                     marks the page you are already on, so "is this a link" and
                     "is this the current page" cannot be set to disagree. --}}
                <nav aria-label="Breadcrumb" class="flex min-w-0 items-center gap-1.5 text-sm">
                    @foreach ($breadcrumb as $index => $crumb)
                        @if ($index > 0)
                            <span aria-hidden="true" class="text-muted/60">/</span>
                        @endif

                        @if (! empty($crumb['href']))
                            <a href="{{ $crumb['href'] }}" class="truncate rounded-control text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ $crumb['label'] }}</a>
                        @else
                            <span aria-current="page" class="truncate font-medium">{{ $crumb['label'] }}</span>
                        @endif
                    @endforeach
                </nav>

                {{-- What this screen lets you do with what you are looking at --
                     a Save button, a status, whatever the route needs. The bar
                     is the shell's; the contents of its right-hand side are the
                     screen's. --}}
                @if ($actions)
                    <div class="ml-auto flex items-center gap-2">{{ $actions }}</div>
                @endif
            </div>

            <div class="min-h-0 flex-1">{{ $slot }}</div>
        </main>
    </div>
</div>
