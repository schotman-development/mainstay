@php
    $preview = $media->sized(['preview' => \Mainstay\Media\Library::preview()])->url('preview');
    $locales = \Mainstay\Facades\Mainstay::locales();
    [$across, $down] = [old('focal.0', $media->focal[0]), old('focal.1', $media->focal[1])];
    $updates = \Mainstay\Navigation::media('update', $media);
@endphp

{{--
 | An image's focal point, set by clicking the image or typing the two
 | percentages, and its alt text in every language: its page's form, and
 | the Content tab's image dialog's.
--}}
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
