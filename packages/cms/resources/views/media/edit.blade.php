@php
    [$updates, $deletes] = [\Mainstay\Navigation::media('update', $media), \Mainstay\Navigation::media('delete', $media)];
@endphp

{{--
 | One image: its alt text in every language, and the focal point every crop
 | of it is centred on, set by clicking the image or typing the two
 | percentages. Read only, with no Save, for whoever the library would not
 | let change it, and Move to trash only for whoever it would let trash it.
--}}
<x-mainstay::admin
    :title="$media->name"
    :breadcrumb="[['label' => 'Media', 'href' => route('mainstay.media')], ['label' => $media->name]]"
>
    <x-slot:actions>
        @if ($deletes)
            <x-mainstay::dropdown align="end" :chevron="false" trigger-class="rounded-control p-1.5 text-muted hover:bg-surface hover:text-ink">
                <x-slot:label><x-mainstay::more-icon :title="$media->name" /></x-slot:label>
                <x-mainstay::dropdown-item danger type="submit" form="trash-form">Move to trash</x-mainstay::dropdown-item>
            </x-mainstay::dropdown>
        @endif
        @if ($updates)
            <x-mainstay::button type="submit" form="media-form">Save</x-mainstay::button>
        @endif
    </x-slot:actions>

    <form id="media-form" method="post" action="{{ route('mainstay.media.update', $media->id) }}" data-dirty-form class="h-full overflow-y-auto">
        @csrf
        <fieldset @disabled(! $updates) class="mx-auto max-w-3xl space-y-6 px-6 py-8">
            <x-mainstay::notices :messages="$errors->all()" />
            @include('mainstay::media.fields')
        </fieldset>
    </form>

    @if ($deletes)
        <form id="trash-form" method="post" action="{{ route('mainstay.media.delete', $media->id) }}">@csrf</form>
    @endif
</x-mainstay::admin>
