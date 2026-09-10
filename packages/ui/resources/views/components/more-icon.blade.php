@props(['title'])

{{--
 | The row/entry menu trigger. Every one of these looks identical, so without
 | the title a screen reader gets a list of buttons called "Actions" and no way
 | to tell which row any of them belongs to.
--}}
<x-mainstay::icon filled>
    <circle cx="8" cy="3.25" r="1.25" />
    <circle cx="8" cy="8" r="1.25" />
    <circle cx="8" cy="12.75" r="1.25" />
</x-mainstay::icon>
<span class="sr-only">Actions for {{ $title }}</span>
