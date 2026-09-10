@props([
    'entries' => [],
    /* The last crumb, and the table's accessible name. Both come from this one
       string so they cannot say different things. */
    'label' => 'Pages',
    /* The primary action for this list, e.g. a New page button. */
    'action' => null,
    /* The collection's own route, for the navigation. Derived from the label
       when the two agree, which they do for every collection in the sidebar. */
    'href' => null,
    'view' => [],
])

@php
    use Mainstay\Ui\EntryList;

    $view = $view + ['query' => '', 'status' => 'All', 'field' => 'modified', 'direction' => 'desc', 'page' => 1, 'perPage' => 8];

    /*
     | React held this in useState and re-rendered. A Blade screen puts it in the
     | query string instead: the sort headers and the pager are links, the search
     | and the filter are a GET form, and the server does the shaping. That is
     | also what makes a sorted list a URL someone can send to a colleague.
     |
     | Anything that changes what is in the list sends you back to its first
     | page; only an explicit page in the patch survives.
     */
    $link = fn (array $patch) => '?'.http_build_query(array_merge($view, ['page' => 1], $patch));

    $shaped = EntryList::shape($entries, $view);
    [$rows, $total, $page, $pageCount, $start] = [$shaped['rows'], $shaped['total'], $shaped['page'], $shaped['pageCount'], $shaped['start']];

    /*
     | The screen *is* the content region: no card, no rule around it, the page's
     | own ground showing through. Which forces the tints to swap -- a chip or a
     | selected row that stood out against a panel is invisible once the panel is
     | gone, so on the page they take the panel colour instead.
     |
     | Spelled out as whole literals rather than composed, because the scanner
     | reads source text: a class assembled at runtime is a class Tailwind never
     | sees and never compiles.
     */
    $cell = 'px-5 py-3.5 align-middle';
    $listing = $href ?? '/admin/collections/'.strtolower($label);

    $columns = ['title' => 'Title', 'status' => 'Status', 'category' => 'Category', 'author' => 'Author', 'released' => 'Released', 'modified' => 'Modified'];
@endphp

{{--
 | Pages and posts are the same list -- a title, whether readers can see it yet,
 | where it is filed, who owns it, and the two dates that matter.
 |
 | A screen rather than a component that gets placed on one: it brings the fixed
 | shell with it and puts its own controls in the shell's bar. There is no
 | toolbar of its own -- the breadcrumb says which list you are looking at, the
 | bar's right-hand side is what you can do to it, and the column headers are
 | the sort control. Everything below the bar is data.
--}}
<x-stories::shell :current="$listing" :breadcrumb="[['label' => 'Collections', 'href' => '/admin/collections'], ['label' => $label]]">
    <x-slot:actions>
        {{ $action }}

        {{-- No point offering to narrow a list that has nothing in it. The one
             action that still makes sense on an empty list is adding to it. --}}
        @if (count($entries) > 0)
            <x-mainstay::dropdown align="end" trigger-class="rounded-control border border-border bg-surface px-2.5 py-1 text-sm text-muted hover:text-ink">
                <x-slot:label>{{ $view['status'] === 'All' ? 'Status' : 'Status: '.$view['status'] }}</x-slot:label>

                @foreach (['All', 'Published', 'Draft'] as $status)
                    <x-mainstay::dropdown-item
                        as="a"
                        :href="$link(['status' => $status])"
                        :aria-current="$view['status'] === $status ? 'true' : null"
                        :class="$view['status'] === $status ? 'font-medium' : null"
                    >{{ $status }}</x-mainstay::dropdown-item>
                @endforeach
            </x-mainstay::dropdown>

            <form method="get" class="contents">
                @foreach (['status', 'field', 'direction', 'perPage'] as $held)
                    <input type="hidden" name="{{ $held }}" value="{{ $view[$held] }}">
                @endforeach

                <x-mainstay::input
                    size="sm"
                    ground="surface"
                    :full-width="false"
                    type="search"
                    name="query"
                    :value="$view['query']"
                    placeholder="Search"
                    :aria-label="'Search '.strtolower($label)"
                    class="w-48"
                />
            </form>
        @endif
    </x-slot:actions>

    @if (count($entries) === 0)
        <div class="grid h-full place-content-center bg-canvas px-4 text-center">
            <p class="text-sm font-medium">Nothing here yet</p>
            <p class="pt-1 text-sm text-muted">The first one you publish shows up in this list.</p>
        </div>
    @else
        <div data-entry-list="{{ $label }}" class="flex h-full min-h-0 flex-col bg-canvas">
            {{-- Everything the search left, not just this page. The bulk bar acts
                 on the selection, so the count it shows has to be scoped the way
                 the actions are -- selection outlives paging, and a bar that
                 forgot the rows behind you would offer to trash fewer than it
                 will. --}}
            <script type="application/json" data-entry-matched>@json(array_column($shaped['matched'], 'path'))</script>

            {{-- The running total is announced from the footer, where it is
                 always mounted; this one only ever speaks about the selection. --}}
            <span data-selection-live aria-live="polite" class="sr-only">Nothing selected</span>

            {{--
             | Stays inside the content region rather than going up into the bar
             | with the other controls: it appears and disappears with the
             | selection, and the fixed chrome changing height as you tick a row
             | is the chrome no longer being fixed.
            --}}
            <div data-selection-bar hidden class="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-surface px-5 py-3">
                <span class="text-sm"><span data-selection-count>0</span> selected</span>

                <x-mainstay::button variant="secondary">Duplicate</x-mainstay::button>
                <x-mainstay::button variant="danger">Move to trash</x-mainstay::button>

                <button type="button" data-selection-clear class="ml-auto rounded-control text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Clear selection</button>
            </div>

            {{-- Nine columns and a menu do not fit a narrow viewport; the table
                 keeps its shape and scrolls inside its own box rather than
                 widening the page. The bar above and the footer below stay put. --}}
            <div class="min-h-0 flex-1 overflow-auto">
                {{-- Named by the same string the breadcrumb's last crumb uses,
                     rather than by a heading of its own -- there is no longer a
                     heading to point at, and two names for one list is one too
                     many. --}}
                <table aria-label="{{ $label }}" class="w-full text-sm">
                    <thead>
                        {{-- A collapsed border belongs to the table grid rather
                             than to the cell, so it does not travel with a stuck
                             header and the rule under it disappears on scroll.
                             An inset shadow is painted on the row itself and
                             stays. --}}
                        <tr class="text-left text-xs text-muted [&>th]:sticky [&>th]:top-0 [&>th]:z-10 [&>th]:bg-canvas [&>th]:shadow-[inset_0_-1px_0_var(--color-border)]">
                            <th scope="col" class="w-12 px-5 py-3">
                                <x-mainstay::checkbox data-select-page label="Select all on this page" :disabled="count($rows) === 0" />
                            </th>
                            <th scope="col" class="w-16 px-3 py-3"><span class="sr-only">Preview</span></th>

                            {{-- The header is the sort control, as in the site
                                 editor. aria-sort goes on the cell rather than
                                 the link -- it describes the column, and a
                                 screen reader reads it when it announces the
                                 header. --}}
                            @foreach ($columns as $field => $heading)
                                @php($active = $view['field'] === $field)
                                <th
                                    scope="col"
                                    @if ($active) aria-sort="{{ $view['direction'] === 'asc' ? 'ascending' : 'descending' }}" @endif
                                    class="px-5 py-3 font-medium"
                                >
                                    <a
                                        href="{{ $link(['field' => $field, 'direction' => $active && $view['direction'] === 'asc' ? 'desc' : 'asc']) }}"
                                        class="flex items-center gap-1 rounded-control hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                    >
                                        {{ $heading }}
                                        <span class="sr-only">, sort</span>
                                        <x-mainstay::icon class="size-3 {{ $active ? '' : 'opacity-0' }} {{ $active && $view['direction'] === 'asc' ? 'rotate-180' : '' }}">
                                            <path d="m4 6 4 4 4-4" />
                                        </x-mainstay::icon>
                                    </a>
                                </th>
                            @endforeach

                            <th scope="col" class="w-10"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>

                    <tbody>
                        @if (count($rows) === 0)
                            <tr>
                                <td colspan="9" class="px-5 py-16 text-center text-sm text-muted">No {{ strtolower($label) }} match that search.</td>
                            </tr>
                        @endif

                        @foreach ($rows as $entry)
                            <tr data-entry-row class="group border-b border-border last:border-b-0 has-[[data-select-row]:checked]:bg-surface">
                                <td class="w-12 px-5 py-3.5 align-middle">
                                    <x-mainstay::checkbox data-select-row :value="$entry['path']" :label="'Select '.$entry['title']" />
                                </td>

                                <td class="w-16 px-3 py-3.5 align-middle">
                                    {{-- Solid rather than dashed: this column is
                                         mostly real previews, and the initial is
                                         a stand-in for the page rather than an
                                         empty slot. --}}
                                    <x-mainstay::thumbnail
                                        :src="$entry['thumbnail'] ?? null"
                                        :fallback="mb_strtoupper(mb_substr(trim($entry['title']), 0, 1)) ?: '?'"
                                    />
                                </td>

                                <td class="{{ $cell }}">
                                    {{-- "/" is a path; "/" + "/edit" is a
                                         protocol-relative URL pointing at a host
                                         called "edit". Trim before joining. --}}
                                    <a href="{{ rtrim($entry['path'], '/').'/edit' }}" class="rounded-control font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ $entry['title'] }}</a>
                                </td>

                                <td class="{{ $cell }}"><x-mainstay::status-chip :status="$entry['status']" ground="surface" /></td>
                                <td class="{{ $cell }} text-muted">{{ $entry['category'] }}</td>
                                <td class="{{ $cell }} whitespace-nowrap text-muted">{{ $entry['author'] }}</td>
                                <td class="{{ $cell }} whitespace-nowrap text-muted"><x-mainstay::date-text :iso="$entry['released']" empty="Not released" /></td>
                                <td class="{{ $cell }} whitespace-nowrap text-muted"><x-mainstay::date-text :iso="$entry['modified']" /></td>

                                {{-- Revealed on hover like the site editor's, but
                                     kept in the DOM: opacity leaves it
                                     focusable, which is what carries a keyboard
                                     to it. has-[details[open]] stops it fading
                                     out from under its own open menu when the
                                     pointer leaves. --}}
                                <td class="px-3 py-3.5 align-middle">
                                    <div class="opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 has-[details[open]]:opacity-100 pointer-coarse:opacity-100">
                                        <x-mainstay::dropdown align="end" :chevron="false" trigger-class="rounded-control p-1 text-muted hover:bg-canvas hover:text-ink">
                                            <x-slot:label><x-mainstay::more-icon :title="$entry['title']" /></x-slot:label>

                                            <x-mainstay::dropdown-item>Edit</x-mainstay::dropdown-item>
                                            @if ($entry['status'] === 'Published')
                                                <x-mainstay::dropdown-item>View</x-mainstay::dropdown-item>
                                            @endif
                                            <x-mainstay::dropdown-item>Duplicate</x-mainstay::dropdown-item>
                                            <x-mainstay::dropdown-item>Rename</x-mainstay::dropdown-item>
                                            <x-mainstay::dropdown-item danger>Move to trash</x-mainstay::dropdown-item>
                                        </x-mainstay::dropdown>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex shrink-0 items-center justify-between gap-2 border-t border-border px-5 py-3 text-xs text-muted">
                <span aria-live="polite">{{ $total === 0 ? 'No results' : ($start + 1).'–'.($start + count($rows)).' of '.$total }}</span>

                <div class="flex items-center gap-2">
                    <x-stories::step :href="$link(['page' => $page - 1])" :disabled="$page === 1" label="Previous page">&lsaquo;</x-stories::step>
                    <span>{{ $page }} of {{ $pageCount }}</span>
                    <x-stories::step :href="$link(['page' => $page + 1])" :disabled="$page === $pageCount" label="Next page">&rsaquo;</x-stories::step>
                </div>
            </div>
        </div>
    @endif
</x-stories::shell>
