@props(['entry', 'collection' => 'Posts', 'site' => 'example.test'])

@php
    use Mainstay\Ui\EntryForm;

    /*
     | What the server holds, and what the form is holding. React kept both in
     | state so the bar could answer "is my work safe?" at all. Here the server
     | only ever renders the saved side -- the form goes dirty in the browser,
     | and dirty-form.ts carries the hint from there.
     */
    $saved = $entry;
    $state = EntryForm::saveState($saved, $entry);
    $listing = '/admin/collections/'.strtolower($collection);
    $empty = ($entry['blocks'] ?? 0) === 0;
@endphp

{{--
 | One entry of a collection, open for editing. The list hands us here: every
 | title in the page list links to `${path}/edit`, and this is what is on the
 | other side of that link.
 |
 | What this screen is NOT is where the writing happens. The block editor is a
 | front-end inline editor -- you edit the page on the rendered site, in place,
 | seeing the real layout. So the body never appears here, and the screen is free
 | to be what it actually is: everything true *about* an entry rather than the
 | entry itself. Slug, summary, filing, search metadata, and the workflow state
 | that decides who can see it.
--}}
<x-stories::shell
    :current="$listing.'/42/edit'"
    :breadcrumb="[
        ['label' => 'Collections', 'href' => '/admin/collections'],
        ['label' => $collection, 'href' => $listing],
        ['label' => $entry['title'] ?: 'Untitled'],
    ]"
>
    <x-slot:actions>
        {{-- aria-live so the answer to "is my work safe?" is announced rather
             than only shown. The region is always mounted; an element that
             appears at the same moment its text does is not announced. --}}
        <span data-save-hint aria-live="polite" class="text-xs text-muted">{{ $state['hint'] }}</span>

        <span data-status-chip><x-mainstay::status-chip :status="$entry['status']" /></span>

        <x-mainstay::dropdown align="end" :chevron="false" trigger-class="rounded-control p-1.5 text-muted hover:bg-surface hover:text-ink">
            <x-slot:label><x-mainstay::more-icon :title="$entry['title'] ?: 'this entry'" /></x-slot:label>

            @if ($entry['status'] === 'Published')
                <x-mainstay::dropdown-item>View on site</x-mainstay::dropdown-item>
            @endif
            <x-mainstay::dropdown-item>Duplicate</x-mainstay::dropdown-item>
            <x-mainstay::dropdown-item>Revisions</x-mainstay::dropdown-item>
            <x-mainstay::dropdown-item data-discard disabled>Discard changes</x-mainstay::dropdown-item>
            <x-mainstay::dropdown-item danger>Move to trash</x-mainstay::dropdown-item>
        </x-mainstay::dropdown>

        <x-mainstay::button :variant="$state['variant']" :disabled="$state['disabled']" type="submit" form="entry-form">{{ $state['label'] }}</x-mainstay::button>
    </x-slot:actions>

    {{-- The shell hands this a box with a definite height; filling it rather
         than growing to fit is what keeps the bar and the navigation still while
         the two columns scroll under them. --}}
    <form id="entry-form" method="post" data-dirty-form class="flex h-full min-h-0">
        {{--
         | min-h-0 on both columns. The shell makes the point for the page; it
         | applies once more here, because a flex child that takes its content
         | height as a floor cannot scroll, and this row has two of them. Miss it
         | on either and the header goes off the top of the screen instead of the
         | body scrolling under it.
        --}}
        <main class="min-h-0 min-w-0 flex-1 overflow-y-auto">
            <div class="mx-auto max-w-3xl space-y-6 px-6 py-8">
                {{--
                 | The handoff. Editing happens on the rendered site, in place, so
                 | this screen's only job regarding the content is to prove it
                 | exists and get you to it. Given the preview rather than a
                 | button alone: a thumbnail is the fastest way to confirm you are
                 | about to edit the right page, and it is the only thing on this
                 | screen that shows what a reader would actually see.
                --}}
                <section aria-label="Content" class="flex items-center gap-4 rounded-control border border-border bg-surface p-4">
                    {{-- No preview when there are no blocks: a preview of a page
                         with nothing on it is a preview of nothing. --}}
                    <x-mainstay::thumbnail size="lg" dashed :src="$empty ? null : ($entry['thumbnail'] ?? null)" fallback="Empty" />

                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-medium">Content</h2>
                        <p class="pt-0.5 text-xs text-muted">{{ $empty ? 'Nothing written yet. The editor opens on the page itself.' : $entry['blocks'].' blocks. Edited on the page, not here.' }}</p>
                    </div>

                    {{-- Leaves the admin, so it is an anchor and says so. Not
                         target="_blank": the inline editor replaces this screen
                         for as long as you are writing, and coming back to a
                         stale form in the tab behind you is how you save over
                         your own metadata. --}}
                    <a href="https://{{ $site }}{{ $entry['path'] }}?edit=1" class="inline-flex shrink-0 items-center gap-2 rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-ink transition-opacity hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                        {{ $empty ? 'Start writing' : 'Edit content' }}
                        <x-mainstay::page-icon />
                    </a>
                </section>

                <x-mainstay::field-group label="Details">
                    <x-mainstay::field label="Title" for="entry-title">
                        <x-mainstay::input id="entry-title" name="title" :value="$entry['title']" placeholder="Untitled" />
                    </x-mainstay::field>

                    {{--
                     | The prefix is shown rather than described, because a slug
                     | field on its own is a box you have to guess the rules of:
                     | leading slash or not, whole URL or the last part. Seeing
                     | the address assemble as you type answers all of it without
                     | a hint line.
                    --}}
                    <x-mainstay::field label="URL" for="entry-path" hint="Changing this breaks any link already pointing at the old address.">
                        <x-mainstay::input-shell class="flex items-stretch">
                            <span class="flex select-none items-center border-r border-border px-2.5 font-mono text-xs text-muted">{{ $site }}</span>
                            <x-mainstay::input bare id="entry-path" name="path" aria-describedby="entry-path-hint" :value="$entry['path']" placeholder="/blog/an-entry" class="min-w-0 rounded-r-control px-2.5 py-1.5 font-mono text-xs" />
                        </x-mainstay::input-shell>
                    </x-mainstay::field>

                    <x-mainstay::field label="Summary" for="entry-excerpt" hint="Shown wherever this entry is listed, quoted or shared.">
                        <x-mainstay::textarea id="entry-excerpt" name="excerpt" aria-describedby="entry-excerpt-hint" rows="3">{{ $entry['excerpt'] }}</x-mainstay::textarea>
                    </x-mainstay::field>
                </x-mainstay::field-group>

                <x-mainstay::field-group label="Organisation">
                    <x-mainstay::field label="Category" for="entry-category">
                        <x-mainstay::select id="entry-category" name="category">
                            @foreach (['Product', 'Engineering', 'Changelog', 'Company'] as $category)
                                <option @selected($category === $entry['category'])>{{ $category }}</option>
                            @endforeach
                        </x-mainstay::select>
                    </x-mainstay::field>

                    <x-mainstay::field label="Tags" for="entry-tags">
                        <x-mainstay::tag-input id="entry-tags" name="tags" :tags="$entry['tags']" />
                    </x-mainstay::field>

                    {{-- The image, or the reason there is not one. Both states
                         are the same size, so setting one does not shift the
                         fields below. --}}
                    <x-mainstay::field label="Featured image" for="entry-featured">
                        <div class="flex items-center gap-3">
                            <x-mainstay::thumbnail size="md" dashed :src="$entry['thumbnail'] ?? null" fallback="None" />

                            <div class="flex gap-2">
                                <x-mainstay::button type="button" variant="secondary" id="entry-featured">{{ ($entry['thumbnail'] ?? null) ? 'Replace' : 'Choose' }}</x-mainstay::button>
                                @if ($entry['thumbnail'] ?? null)
                                    <x-mainstay::button type="button" variant="secondary">Remove</x-mainstay::button>
                                @endif
                            </div>
                        </div>
                    </x-mainstay::field>
                </x-mainstay::field-group>

                {{--
                 | Its own group rather than fields mixed into Details, because
                 | these are written for a machine and read by a stranger. Left
                 | blank they fall back to the title and summary above, which is
                 | why the placeholders show what would be used instead.
                --}}
                <x-mainstay::field-group label="Search">
                    <x-mainstay::field label="Meta title" for="entry-seo-title">
                        <x-mainstay::input id="entry-seo-title" name="seoTitle" :value="$entry['seoTitle']" :placeholder="$entry['title'] ?: 'Falls back to the title'" />
                    </x-mainstay::field>

                    <x-mainstay::field
                        label="Meta description"
                        for="entry-seo-description"
                        :counter="['length' => mb_strlen($entry['seoDescription']), 'limit' => \Mainstay\Ui\EntryForm::SEO_DESCRIPTION_LIMIT]"
                    >
                        <x-mainstay::textarea id="entry-seo-description" name="seoDescription" rows="3" :placeholder="$entry['excerpt'] ?: 'Falls back to the summary'">{{ $entry['seoDescription'] }}</x-mainstay::textarea>
                    </x-mainstay::field>
                </x-mainstay::field-group>
            </div>
        </main>

        {{--
         | The rail holds what is deliberately *not* metadata: whether readers
         | can see this, from when, and whose name is on it. Workflow rather than
         | content about the content, which is why it survives the form being the
         | main column now.
        --}}
        <aside aria-label="Publishing" class="min-h-0 w-72 shrink-0 overflow-y-auto border-l border-border bg-surface">
            <x-mainstay::field-section label="Status">
                {{-- The heading above is visual grouping and labels nothing, so
                     the trigger carries the field name itself -- otherwise the
                     rail is a button called "Draft" with no way to tell what it
                     sets. The same shape the page list uses for its own status
                     filter. --}}
                {{-- The value posts from a hidden input rather than from the
                     trigger, because a <details> is not a form control. The
                     items carry the value they set; setting it is the bundle's
                     job, which is also what keeps the chip in the bar in step. --}}
                <input type="hidden" name="status" value="{{ $entry['status'] }}" data-status-value>

                <x-mainstay::dropdown data-status trigger-class="w-full justify-between rounded-control border border-border bg-canvas px-2.5 py-1.5 text-sm hover:bg-surface">
                    <x-slot:label><span class="sr-only">Status: </span><span data-status-label>{{ $entry['status'] }}</span></x-slot:label>

                    @foreach (['Draft', 'Published'] as $status)
                        <x-mainstay::dropdown-item
                            data-status-option
                            :value="$status"
                            :aria-current="$entry['status'] === $status ? 'true' : null"
                            :class="$entry['status'] === $status ? 'font-medium' : null"
                        >{{ $status }}</x-mainstay::dropdown-item>
                    @endforeach
                </x-mainstay::dropdown>
            </x-mainstay::field-section>

            {{-- Native, so it brings its own picker, its own keyboard handling
                 and its own locale formatting for free. An empty value is how
                 the control spells null. --}}
            <x-mainstay::field label="Released" for="entry-released" rail>
                <x-mainstay::input id="entry-released" name="released" type="date" :value="$entry['released'] ?? ''" />
            </x-mainstay::field>

            {{-- Not a control: who wrote it is not yours to set from here. --}}
            <x-mainstay::field-section label="Author">
                <p class="text-sm">{{ $entry['author'] }}</p>
                <p class="pt-1 text-xs text-muted">Last saved <x-mainstay::date-text :iso="$saved['modified']" /></p>
            </x-mainstay::field-section>
        </aside>
    </form>
</x-stories::shell>
