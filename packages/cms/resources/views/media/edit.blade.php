@php
    $preview = $media->sized(['preview' => \Mainstay\Media\Library::preview()])->url('preview');
    $locales = \Mainstay\Facades\Mainstay::locales();
    [$across, $down] = [old('focal.0', $media->focal[0]), old('focal.1', $media->focal[1])];
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

            <div @if ($updates) data-focal @endif class="relative w-fit cursor-crosshair select-none">
                <img src="{{ $preview }}" alt="" data-focal-image class="block max-h-[28rem] max-w-full rounded-control border border-border">
                <span data-focal-marker aria-hidden="true" style="left: {{ $across }}%; top: {{ $down }}%" class="pointer-events-none absolute size-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-accent/70 shadow"></span>
            </div>

            <x-mainstay::field-group label="Image">
                <x-mainstay::field label="Focal point, across (%)" for="focal-across">
                    <x-mainstay::input id="focal-across" type="number" name="focal[0]" min="0" max="100" step="1" :value="$across" data-focal-across />
                </x-mainstay::field>
                <x-mainstay::field label="Focal point, down (%)" for="focal-down">
                    <x-mainstay::input id="focal-down" type="number" name="focal[1]" min="0" max="100" step="1" :value="$down" data-focal-down />
                </x-mainstay::field>
                @foreach ($locales as $code => $locale)
                    <x-mainstay::field :label="'Alt text'.(count($locales) > 1 ? ' ('.strtoupper($code).')' : '')" :for="'alt-'.$code" hint="Empty marks the image decorative.">
                        <x-mainstay::input :id="'alt-'.$code" :name="'alt['.$code.']'" maxlength="1000" :value="old('alt.'.$code, $alt[$code])" :aria-describedby="'alt-'.$code.'-hint'" />
                    </x-mainstay::field>
                @endforeach
            </x-mainstay::field-group>
        </fieldset>
    </form>

    @if ($deletes)
        <form id="trash-form" method="post" action="{{ route('mainstay.media.delete', $media->id) }}">@csrf</form>
    @endif
</x-mainstay::admin>
