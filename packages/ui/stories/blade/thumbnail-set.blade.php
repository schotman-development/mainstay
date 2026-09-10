@props(['empty' => false])

@php($preview = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 28'%3E%3Crect width='40' height='28' fill='%23e7e5e0'/%3E%3Crect x='5' y='6' width='30' height='3' rx='1.5' fill='%238b8880'/%3E%3Crect x='5' y='13' width='21' height='2.5' rx='1.25' fill='%23bdbab3'/%3E%3Crect x='5' y='19' width='26' height='2.5' rx='1.25' fill='%23bdbab3'/%3E%3C/svg%3E")

{{--
 | The three sizes side by side, which is the only way to see that they are a
 | set rather than three numbers that happen to be near each other -- and the
 | empty states at the same sizes underneath. The pairing is the point: each box
 | is exactly the size the image would have been, so choosing one shifts nothing
 | below it.
--}}
<div class="flex items-end gap-4 font-sans">
    @if ($empty)
        {{-- Solid, as in a list beside real previews. --}}
        <x-mainstay::thumbnail size="sm" fallback="A" />
        <x-mainstay::thumbnail size="md" dashed fallback="None" />
        <x-mainstay::thumbnail size="lg" dashed fallback="Empty" />
    @else
        <x-mainstay::thumbnail :src="$preview" size="sm" fallback="A" />
        <x-mainstay::thumbnail :src="$preview" size="md" fallback="None" />
        <x-mainstay::thumbnail :src="$preview" size="lg" fallback="Empty" />
    @endif
</div>
