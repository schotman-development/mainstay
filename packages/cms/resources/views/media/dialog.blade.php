@php
    $updates = \Mainstay\Navigation::media('update', $media);
@endphp

{{--
 | One image's form, fetched into the Content tab's image dialog: posted for
 | JSON, and the block showing it drawn again once it is saved. Read only,
 | with no Save, for whoever the library would not let change it.
--}}
<form method="post" action="{{ route('mainstay.media.update', $media->id) }}" data-image-form>
    @csrf
    <fieldset @disabled(! $updates) class="space-y-6">
        <div data-image-messages role="alert" hidden class="space-y-1 rounded-control border border-danger/40 bg-surface px-3 py-2 text-sm text-danger"></div>
        @include('mainstay::media.fields')
    </fieldset>
    @if ($updates)
        <div class="mt-6 flex justify-end">
            <x-mainstay::button type="submit">Save</x-mainstay::button>
        </div>
    @endif
</form>
