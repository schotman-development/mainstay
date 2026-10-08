@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php
    /* The image as read -- or, after a refused save, the id posted, read
       again -- and the admin's preview of it. One the library no longer
       holds keeps its id and shows as missing until it is replaced. */
    $media = match (true) {
        $value instanceof \Mainstay\Content\Media => $value,
        is_string($value) && ctype_digit($value) => \Mainstay\Http\Form::readable(fn () => \Mainstay\Facades\Mainstay::media()->find((int) $value)) ?? new \Mainstay\Content\Media((int) $value),
        default => null,
    };
    $src = $media?->missing === false ? $media->sized(['preview' => \Mainstay\Media\Library::preview()])->url('preview') : null;
@endphp

{{--
 | A thumbnail, the image's id in a hidden input, and Choose, Replace and
 | Remove. Choose opens the picker: the library's grid, fetched into the one
 | dialog every image field shares.
--}}
<x-mainstay::field-section :label="$field->label()">
    <div data-image-field data-picker="{{ route('mainstay.media', ['pick' => 1]) }}" class="flex items-center gap-3">
        <input type="hidden" name="{{ $name }}" value="{{ $media?->id }}">
        <span data-image-preview>
            <x-mainstay::thumbnail size="md" dashed :src="$src" :fallback="$media?->missing ? 'Missing' : 'None'" />
        </span>
        <div class="flex gap-2">
            <x-mainstay::button type="button" variant="secondary" data-image-choose>{{ $media ? 'Replace' : 'Choose' }}</x-mainstay::button>
            <x-mainstay::button type="button" variant="secondary" data-image-remove :hidden="$media === null">Remove</x-mainstay::button>
        </div>
        <template data-image-chosen><x-mainstay::thumbnail size="md" src="about:blank" fallback="" /></template>
        <template data-image-empty><x-mainstay::thumbnail size="md" dashed fallback="None" /></template>
    </div>
    <x-mainstay::messages :id="'field-'.$name.'-error'" :messages="$messages" />
</x-mainstay::field-section>

@once
    @push('dialogs')
        <x-mainstay::dialog id="mainstay-picker" label="Choose an image" wide>
            <div data-picker-body aria-live="polite" class="max-h-[70vh] overflow-y-auto">
                <p class="text-sm text-muted">Loading the library…</p>
            </div>
        </x-mainstay::dialog>
    @endpush
@endonce
