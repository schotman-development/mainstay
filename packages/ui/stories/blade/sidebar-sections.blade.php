@props(['current' => null])

{{-- The panel owns its own background; the preview's canvas padding fights it. --}}
<div class="-m-6 flex h-[32rem] font-sans text-ink">
    <x-stories::demo-sidebar :current="$current" />
</div>
