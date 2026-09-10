@props(['size' => 24])

{{--
 | The horizontal lockup. Proportions come off the design's lockups -- wordmark
 | at ~0.75 of the mark, gap at ~0.32 -- kept as ratios rather than hard pixel
 | values so one size prop drives the whole thing.
 |
 | The design doc sets the wordmark in Instrument Sans; this uses the theme's
 | own font-sans (Poppins) at the same weight, case and tracking. Shipping a
 | second family for eight letters is not worth 15 KB in the admin bundle.
 |
 | No colour of its own. Setting text-ink here would sit at the same specificity
 | as a caller's text-canvas and win on sheet order, which is exactly the
 | knockout case -- so the lockup inherits, like the mark.
--}}
<span {{ $attributes->class(['inline-flex items-center']) }} style="gap: {{ $size * 0.32 }}px">
    <x-mainstay::logo-mark :size="$size" lockup />
    <span class="font-sans font-medium uppercase leading-none" style="font-size: {{ $size * 0.75 }}px; letter-spacing: 0.02em">Mainstay</span>
</span>
