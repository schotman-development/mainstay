@php
    $handle = $class::handle();
    [$rows, $total, $page, $pageCount, $start] = [$shaped['rows'], $shaped['total'], $shaped['page'], $shaped['pageCount'], $shaped['start']];
    $asked = $locale === array_key_first(\Mainstay\Facades\Mainstay::locales()) ? null : $locale;
    $link = fn (array $patch) => '?'.http_build_query(array_filter(array_merge($view, ['page' => 1, 'perPage' => null, 'locale' => $asked], $patch), fn ($value) => $value !== null && $value !== ''));
    $columns = $trashed
        ? ['title' => 'Title', 'author' => 'Author', 'modified' => 'Trashed']
        : ['title' => 'Title', 'status' => 'Status', 'author' => 'Author', 'modified' => 'Last change'];
    $cell = 'px-5 py-3.5 align-middle';
    $list = route('mainstay.entries', $handle);
    $localized = fn (string $url) => $asked ? $url.'?locale='.$asked : $url;
    $selectable = ! $trashed;
@endphp

{{--
 | A type's entries, or its trash: the title, whether readers can see it,
 | whose it is and when it last changed. Search, filter, sort and page are in
 | the query string and shaped on the server; selection is the browser's.
--}}
<x-mainstay::admin
    :title="$trashed ? $label.' trash' : $label"
    :breadcrumb="$trashed ? [['label' => $label, 'href' => $localized($list)], ['label' => 'Trash']] : [['label' => $label]]"
>
    <x-slot:actions>
        <x-mainstay::locales :locale="$locale" />

        @unless ($empty)
            @if ($drafts)
                <x-mainstay::dropdown align="end" trigger-class="rounded-control border border-border bg-surface px-2.5 py-1 text-sm text-muted hover:text-ink">
                    <x-slot:label>{{ $view['status'] === 'All' ? 'Status' : 'Status: '.$view['status'] }}</x-slot:label>

                    @foreach (['All', 'Published', 'Changed', 'Draft'] as $status)
                        <x-mainstay::dropdown-item
                            as="a"
                            :href="$link(['status' => $status === 'All' ? null : $status])"
                            :aria-current="$view['status'] === $status ? 'true' : null"
                            :class="$view['status'] === $status ? 'font-medium' : null"
                        >{{ $status }}</x-mainstay::dropdown-item>
                    @endforeach
                </x-mainstay::dropdown>
            @endif

            <form method="get" class="contents">
                @foreach (['status' => $view['status'] === 'All' ? null : $view['status'], 'field' => $view['field'], 'direction' => $view['direction'], 'locale' => $asked] as $held => $value)
                    @if ($value !== null)
                        <input type="hidden" name="{{ $held }}" value="{{ $value }}">
                    @endif
                @endforeach

                <x-mainstay::input size="sm" ground="surface" :full-width="false" type="search" name="query" :value="$view['query']" placeholder="Search" :aria-label="'Search '.strtolower($label)" class="w-48" />
            </form>
        @endunless

        @unless ($trashed)
            <a href="{{ $localized(route('mainstay.entries.trash', $handle)) }}" class="rounded-control px-2 py-1 text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Trash</a>
            <a href="{{ $localized(route('mainstay.entries.create', $handle)) }}" class="inline-flex items-center rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-ink hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">New {{ strtolower(\Mainstay\Navigation::label($class, plural: false)) }}</a>
        @endunless
    </x-slot:actions>

    <div class="flex h-full min-h-0 flex-col">
        <x-mainstay::notices :messages="$errors->all()" class="px-5 pt-4" />

        @if ($empty)
            <div class="grid flex-1 place-content-center px-4 text-center">
                <p class="text-sm font-medium">{{ $trashed ? 'The trash is empty' : 'Nothing here yet' }}</p>
                <p class="pt-1 text-sm text-muted">{{ $trashed ? 'What you move to the trash waits here until you restore it or delete it for good.' : 'The first one you save shows up in this list.' }}</p>
            </div>
        @else
            <div data-entry-list="{{ $handle }}" class="flex min-h-0 flex-1 flex-col">
                @if ($selectable)
                    <script type="application/json" data-entry-matched>@json(array_column(array_filter($shaped['matched'], fn (array $row) => $row['id'] !== null), 'path'))</script>
                    <span data-selection-live aria-live="polite" class="sr-only">Nothing selected</span>

                    <div data-selection-bar hidden class="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-surface px-5 py-3">
                        <span class="text-sm"><span data-selection-count>0</span> selected</span>

                        <form method="post" action="{{ route('mainstay.entries.trash-many', $handle) }}" data-selection-form>
                            @csrf
                            <x-mainstay::button variant="danger" type="submit">Move to trash</x-mainstay::button>
                        </form>

                        <button type="button" data-selection-clear class="ml-auto rounded-control text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Clear selection</button>
                    </div>
                @endif

                <div class="min-h-0 flex-1 overflow-auto">
                    <table aria-label="{{ $trashed ? $label.' trash' : $label }}" class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-muted [&>th]:sticky [&>th]:top-0 [&>th]:z-10 [&>th]:bg-canvas [&>th]:shadow-[inset_0_-1px_0_var(--color-border)]">
                                @if ($selectable)
                                    <th scope="col" class="w-12 px-5 py-3">
                                        <x-mainstay::checkbox data-select-page label="Select all on this page" :disabled="count($rows) === 0" />
                                    </th>
                                @endif

                                @foreach ($columns as $field => $heading)
                                    @php($active = $view['field'] === $field)
                                    <th scope="col" @if ($active) aria-sort="{{ $view['direction'] === 'asc' ? 'ascending' : 'descending' }}" @endif class="px-5 py-3 font-medium">
                                        <a href="{{ $link(['field' => $field, 'direction' => $active && $view['direction'] === 'asc' ? 'desc' : 'asc']) }}" class="flex items-center gap-1 rounded-control hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                                            {{ $heading }}
                                            <span class="sr-only">, sort</span>
                                            <x-mainstay::icon class="size-3 {{ $active ? '' : 'opacity-0' }} {{ $active && $view['direction'] === 'asc' ? 'rotate-180' : '' }}"><path d="m4 6 4 4 4-4" /></x-mainstay::icon>
                                        </a>
                                    </th>
                                @endforeach

                                <th scope="col" class="w-10"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>

                        <tbody>
                            @if (count($rows) === 0)
                                <tr>
                                    <td colspan="{{ count($columns) + 2 }}" class="px-5 py-16 text-center text-sm text-muted">Nothing matches that search.</td>
                                </tr>
                            @endif

                            @foreach ($rows as $row)
                                <tr data-entry-row class="group border-b border-border last:border-b-0 has-[[data-select-row]:checked]:bg-surface">
                                    @if ($selectable)
                                        <td class="w-12 px-5 py-3.5 align-middle">
                                            @if ($row['id'] !== null)
                                                <x-mainstay::checkbox data-select-row :value="$row['path']" :label="'Select '.($row['title'] ?: 'Untitled')" />
                                            @endif
                                        </td>
                                    @endif

                                    <td class="{{ $cell }}">
                                        @if ($trashed)
                                            <span class="font-medium">{{ $row['title'] ?: 'Untitled' }}</span>
                                        @else
                                            <a href="{{ $row['edit'] }}" class="rounded-control font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ $row['title'] ?: 'Untitled' }}</a>
                                        @endif

                                        @if ($row['front'])
                                            <span class="ml-2 text-xs text-muted">Front page</span>
                                        @endif

                                        @if ($row['missing'])
                                            <span class="ml-2 text-xs text-muted">Not in {{ strtoupper($locale) }} yet</span>
                                        @endif
                                    </td>

                                    @unless ($trashed)
                                        <td class="{{ $cell }}"><x-mainstay::status-chip :status="$row['status']" ground="surface" /></td>
                                    @endunless
                                    <td class="{{ $cell }} whitespace-nowrap text-muted">{{ $row['author'] }}</td>
                                    <td class="{{ $cell }} whitespace-nowrap text-muted"><x-mainstay::date-text :iso="$row['modified']" /></td>

                                    <td class="px-3 py-3.5 align-middle">
                                        <div class="opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 has-[details[open]]:opacity-100 pointer-coarse:opacity-100">
                                            <x-mainstay::dropdown align="end" :chevron="false" trigger-class="rounded-control p-1 text-muted hover:bg-canvas hover:text-ink">
                                                <x-slot:label><x-mainstay::more-icon :title="$row['title'] ?: 'Untitled'" /></x-slot:label>

                                                @if ($trashed)
                                                    @if ($row['restores'])
                                                        <form method="post" action="{{ route('mainstay.entries.restore', [$handle, $row['id']]) }}">
                                                            @csrf
                                                            <x-mainstay::dropdown-item type="submit">Restore</x-mainstay::dropdown-item>
                                                        </form>
                                                    @endif
                                                    @if ($row['destroys'])
                                                        <x-mainstay::dropdown-item danger :data-dialog-open="'destroy-'.$row['id']">Delete for good</x-mainstay::dropdown-item>
                                                    @endif
                                                @else
                                                    <x-mainstay::dropdown-item as="a" :href="$row['edit']">Edit</x-mainstay::dropdown-item>
                                                    @if ($row['view'])
                                                        <x-mainstay::dropdown-item as="a" :href="$row['view']" target="_blank" rel="noreferrer">View</x-mainstay::dropdown-item>
                                                    @endif
                                                    @if ($row['trashes'])
                                                        <form method="post" action="{{ route('mainstay.entries.delete', [$handle, $row['id']]) }}">
                                                            @csrf
                                                            <x-mainstay::dropdown-item danger type="submit">Move to trash</x-mainstay::dropdown-item>
                                                        </form>
                                                    @elseif ($row['id'] === null)
                                                        <x-mainstay::dropdown-item danger :data-dialog-open="'discard-'.$row['draft']">Discard draft</x-mainstay::dropdown-item>
                                                    @endif
                                                @endif
                                            </x-mainstay::dropdown>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-mainstay::pager :start="$start" :shown="count($rows)" :total="$total" :page="$page" :page-count="$pageCount" :href="fn (int $to) => $link(['page' => $to])" />
            </div>

            {{-- Outside the table, since a form cannot sit inside another's
                 rows and a dialog's form must not sit in one at all. --}}
            @foreach ($rows as $row)
                @if ($row['destroys'])
                    <x-mainstay::dialog :id="'destroy-'.$row['id']" label="Delete for good?">
                        <form method="post" action="{{ route('mainstay.entries.destroy', [$handle, $row['id']]) }}" class="space-y-4">
                            @csrf
                            <p>{{ $row['title'] ?: 'Untitled' }} goes for good, with its translations, its draft and its history. This cannot be undone.</p>
                            <div class="flex justify-end gap-2">
                                <x-mainstay::button type="button" variant="secondary" data-dialog-close>Keep it</x-mainstay::button>
                                <x-mainstay::button type="submit" variant="danger">Delete for good</x-mainstay::button>
                            </div>
                        </form>
                    </x-mainstay::dialog>
                @elseif ($row['id'] === null)
                    <x-mainstay::dialog :id="'discard-'.$row['draft']" label="Discard this draft?">
                        <form method="post" action="{{ route('mainstay.drafts.discard', [$handle, $row['draft']]) }}" class="space-y-4">
                            @csrf
                            <input type="hidden" name="_draft_at" value="{{ $row['modified'] }}">
                            <p>{{ $row['title'] ?: 'Untitled' }} has never been published, so nothing of it is left once its draft goes.</p>
                            <div class="flex justify-end gap-2">
                                <x-mainstay::button type="button" variant="secondary" data-dialog-close>Keep it</x-mainstay::button>
                                <x-mainstay::button type="submit" variant="danger">Discard draft</x-mainstay::button>
                            </div>
                        </form>
                    </x-mainstay::dialog>
                @endif
            @endforeach
        @endif
    </div>
</x-mainstay::admin>
