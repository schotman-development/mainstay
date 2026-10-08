@php
    $moment = fn ($at) => $at === null ? null : $at->setTimezone('UTC');
    $asked = is_string(request()->query('locale')) ? request()->query('locale') : null;
    $line = 'flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-border px-5 py-3.5';
@endphp

{{--
 | An entry's versions, newest first: the live one, and each a write
 | replaced, with when it was live and who had published it. Restore makes
 | one the draft, which leaves through Publish like any other.
--}}
<x-mainstay::admin
    :title="'History of '.$title"
    :breadcrumb="$live instanceof \Mainstay\Content\GlobalSet || $live === null ? [['label' => $label, 'href' => $form], ['label' => 'History']] : [['label' => $label, 'href' => route('mainstay.entries', $handle)], ['label' => $title, 'href' => $form], ['label' => 'History']]"
>
    <div class="h-full overflow-y-auto">
        <div class="mx-auto max-w-3xl space-y-4 px-6 py-8">
            <x-mainstay::notices :messages="$errors->all()" />

            <ol class="overflow-hidden rounded-control border border-border bg-canvas">
                @if ($live !== null)
                    <li class="{{ $line }}">
                        <span class="text-sm"><x-mainstay::status-chip status="Published" /> <span class="pl-2">Live since {{ $moment($since)?->format('j F Y, H:i') }} UTC</span></span>
                        <span class="text-sm text-muted">Published by {{ $who($live->publishedBy) }}</span>
                    </li>
                @endif

                @forelse ($revisions as $revision)
                    <li class="{{ $line }}">
                        <span class="text-sm">Live until {{ $moment($revision->createdAt)->format('j F Y, H:i') }} UTC</span>
                        <span class="flex items-center gap-3 text-sm text-muted">
                            Published by {{ $who($revision->publishedBy) }}
                            @if ($restores)
                                <form method="post" action="{{ $id === null ? route('mainstay.globals.revisions.restore', [$handle, $revision->id, 'locale' => $asked]) : route('mainstay.revisions.restore', [$handle, $id, $revision->id, 'locale' => $asked]) }}">
                                    @csrf
                                    <x-mainstay::button type="submit" variant="secondary">Restore</x-mainstay::button>
                                </form>
                            @endif
                        </span>
                    </li>
                @empty
                    <li class="px-5 py-3.5 text-sm text-muted">No earlier versions: nothing published has been replaced yet.</li>
                @endforelse
            </ol>
        </div>
    </div>
</x-mainstay::admin>
