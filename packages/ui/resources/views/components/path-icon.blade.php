@props(['d'])

{{--
 | An icon that is one path, which most of them are. Saves every navigation
 | entry from spelling out an <x-mainstay::icon><path/></x-mainstay::icon> pair
 | just to hold a `d`.
--}}
<x-mainstay::icon {{ $attributes }}>
    <path d="{{ $d }}" />
</x-mainstay::icon>
