{{--
 | The point of the size ladder: at 64 the mark is the full construction, at 32
 | the mast is shortened, at 24 the spreader goes, at 16 only the triangle is
 | left. Rendered at 4x underneath so the shed parts are actually visible.
--}}
<div class="flex items-end gap-8 text-ink">
    @foreach ([64, 32, 24, 16] as $size)
        <div class="flex flex-col items-center gap-4">
            <x-mainstay::logo-mark :size="$size" :label="'Mainstay, '.$size.' pixels'" />
            <span class="text-xs text-muted">{{ $size }}px</span>
            <div class="opacity-40">
                <x-mainstay::logo-mark :size="$size * 4" />
            </div>
        </div>
    @endforeach
</div>
