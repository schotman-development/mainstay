@props(['size' => 24])

{{--
 | The horizontal lockup, as the Mainstay website sets it -- wordmark at ~0.71
 | of the mark, gap at ~0.33 -- kept as ratios rather than hard pixel values so
 | one size prop drives the whole thing.
 |
 | No colour of its own. Setting text-ink here would sit at the same specificity
 | as a caller's text-canvas and win on sheet order, which is exactly the
 | knockout case -- so the lockup inherits, like the mark.
--}}
<span {{ $attributes->class(['inline-flex items-center']) }} style="gap: {{ $size / 3 }}px">
    <x-mainstay::logo-mark :size="$size" />
    <span class="font-sans font-semibold leading-none" style="font-size: {{ $size * 17 / 24 }}px; letter-spacing: -0.01em">Mainstay</span>
</span>
