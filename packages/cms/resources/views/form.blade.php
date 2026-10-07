@php
    $list = route('mainstay.entries', $handle);
    $asked = $locale === array_key_first(\Mainstay\Facades\Mainstay::locales()) ? null : $locale;
    $localized = fn (string $url) => $asked ? $url.(str_contains($url, '?') ? '&' : '?').'locale='.$asked : $url;
    $state = \Mainstay\Ui\EntryForm::saveState(drafted: $draft !== null, publishes: $publishes);
    $shared = count(\Mainstay\Facades\Mainstay::locales()) > 1;
    /* An error no field on this screen is there to show: a stale draft, the
       front page, a path that names no field. */
    $loose = collect($errors->getMessages())->reject(fn ($messages, $key) => isset($fields[$key]) || $key === 'template')->flatten()->all();
@endphp

{{--
 | One entry, term or global, open in one language. The fields in the order the
 | type declares them, each drawn by its type's component; the rail holds what
 | is not a field -- whether readers can see it, where, and whose it is.
 |
 | The buttons are in the shell's bar, outside the form, and name it with
 | `form`. So is everything that posts somewhere else -- the trash, the front
 | page, a discard -- since a form cannot sit inside another.
--}}
<x-mainstay::admin
    :title="$title"
    :breadcrumb="$global ? [['label' => $label]] : [['label' => $label, 'href' => $localized($list)], ['label' => $title]]"
>
    <x-slot:actions>
        <x-mainstay::locales :locale="$locale" />

        <span data-save-hint="{{ $state['hint'] }}" aria-live="polite" class="text-xs text-muted">{{ $state['hint'] }}</span>

        @unless ($term)
            <x-mainstay::status-chip :status="$published ? 'Published' : 'Draft'" />
        @endunless

        @if ($addresses !== [] || $state['discard'] || $deletes || ($routed && $live && ! $front))
            <x-mainstay::dropdown align="end" :chevron="false" trigger-class="rounded-control p-1.5 text-muted hover:bg-surface hover:text-ink">
                <x-slot:label><x-mainstay::more-icon :title="$title" /></x-slot:label>

                @foreach ($addresses as $code => $address)
                    <x-mainstay::dropdown-item as="a" :href="$address" target="_blank" rel="noreferrer">View{{ count($addresses) > 1 ? ' in '.strtoupper($code) : '' }}</x-mainstay::dropdown-item>
                @endforeach
                @if ($routed && $live && ! $front && $publishes)
                    <x-mainstay::dropdown-item type="submit" form="front-form">Use as front page</x-mainstay::dropdown-item>
                @endif
                @if ($state['discard'])
                    <x-mainstay::dropdown-item danger data-dialog-open="discard">Discard draft</x-mainstay::dropdown-item>
                @endif
                @if ($deletes)
                    <x-mainstay::dropdown-item danger type="submit" form="trash-form">Move to trash</x-mainstay::dropdown-item>
                @endif
            </x-mainstay::dropdown>
        @endif

        @if ($term)
            <x-mainstay::button type="submit" form="entry-form">Save</x-mainstay::button>
        @else
            {{-- Save draft leads while there are unsaved edits, Publish once
                 there are none: dirty-form.ts marks the page, and these
                 follow it. --}}
            <x-mainstay::button variant="secondary" type="submit" form="entry-form" class="in-data-dirty:border-transparent in-data-dirty:bg-accent in-data-dirty:text-accent-ink">Save draft</x-mainstay::button>
            @if ($state['publish'])
                <x-mainstay::button type="submit" form="entry-form" name="intent" value="publish" class="in-data-dirty:border in-data-dirty:border-border in-data-dirty:bg-surface in-data-dirty:text-ink">Publish</x-mainstay::button>
            @endif
        @endif
    </x-slot:actions>

    <form id="entry-form" method="post" action="{{ $action }}" data-dirty-form class="flex h-full min-h-0">
        @csrf
        <input type="hidden" name="_draft_at" value="{{ $draft?->updatedAt->toIso8601String() }}">
        @foreach ($seen as $name => $print)
            <input type="hidden" name="_seen[{{ $name }}]" value="{{ $print }}">
        @endforeach

        <div class="min-h-0 min-w-0 flex-1 overflow-y-auto">
            <div class="mx-auto max-w-3xl space-y-6 px-6 py-8">
                <x-mainstay::notices :messages="$loose" />

                @if ($missing)
                    <p class="rounded-control border border-border bg-surface px-3 py-2 text-sm">There is no {{ strtoupper($locale) }} version yet. Saving adds one; the fields every language shares are filled in already.</p>
                @endif

                <x-mainstay::field-group :label="$global ? $label : \Mainstay\Navigation::label($class, plural: false)">
                    @foreach ($fields as $name => $field)
                        <x-dynamic-component
                            :component="$field->component()"
                            :field="$field"
                            :name="$name"
                            :value="old($name, $values[$name])"
                            :messages="$errors->get($name)"
                            :hint="$shared && ! $field->localized ? 'The same in every language.' : null"
                            :data-slug-from="$name === $slug ? 'title' : null"
                        />
                    @endforeach
                </x-mainstay::field-group>
            </div>
        </div>

        <aside aria-label="Publishing" class="min-h-0 w-72 shrink-0 overflow-y-auto border-l border-border bg-surface">
            @if ($front)
                <x-mainstay::field-section label="Front page">
                    <p class="text-sm">Served at the site's root.</p>
                </x-mainstay::field-section>
            @endif

            @if ($addresses !== [])
                <x-mainstay::field-section label="Address">
                    @foreach ($addresses as $code => $address)
                        <a href="{{ $address }}" target="_blank" rel="noreferrer" class="block truncate font-mono text-xs text-muted hover:text-ink">{{ $address }}</a>
                    @endforeach
                </x-mainstay::field-section>
            @endif

            @if (count($templates) > 1)
                <x-mainstay::field label="Template" for="field-template" rail>
                    <x-mainstay::select id="field-template" name="template">
                        @foreach ($templates as $view)
                            <option value="{{ $view }}" @selected(old('template', $template) === $view)>{{ $view }}</option>
                        @endforeach
                    </x-mainstay::select>
                    <x-mainstay::messages id="field-template-error" :messages="$errors->get('template')" />
                </x-mainstay::field>
            @endif

            @unless ($global)
                <x-mainstay::field-section label="Author">
                    <p class="text-sm">{{ $owner ?? 'Nobody' }}</p>
                </x-mainstay::field-section>
            @endunless
        </aside>
    </form>

    @if ($deletes)
        <form id="trash-form" method="post" action="{{ $localized(route('mainstay.entries.delete', [$handle, $id])) }}">@csrf</form>
    @endif

    @if ($routed && $live && ! $front)
        <form id="front-form" method="post" action="{{ route('mainstay.entries.front', [$handle, $id]) }}">@csrf</form>
    @endif

    @if ($state['discard'])
        <x-mainstay::dialog id="discard" label="Discard this draft?">
            <form method="post" action="{{ $localized(route('mainstay.drafts.discard', [$handle, $draft->id])) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="_draft_at" value="{{ $draft->updatedAt->toIso8601String() }}">
                <p>{{ $live || $global ? 'The changes waiting to be published go, and what is live stays as it is.' : 'This has never been published, so nothing of it is left once its draft goes.' }}</p>
                <div class="flex justify-end gap-2">
                    <x-mainstay::button type="button" variant="secondary" data-dialog-close>Keep it</x-mainstay::button>
                    <x-mainstay::button type="submit" variant="danger">Discard draft</x-mainstay::button>
                </div>
            </form>
        </x-mainstay::dialog>
    @endif
</x-mainstay::admin>
