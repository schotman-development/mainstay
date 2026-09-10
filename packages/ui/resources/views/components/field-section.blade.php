@props(['label'])

{{--
 | A labelled block for a control that cannot take a `for`: a dropdown's trigger
 | is a <summary> generated inside the component, with no id to point a label
 | at. The heading is visual grouping only, so the control inside carries its
 | own accessible name. Anything with a real form control should use
 | <x-mainstay::field> instead and get a genuine <label>.
--}}
<div class="border-b border-border px-4 py-3">
    <h2 class="pb-1.5 text-xs font-medium text-muted">{{ $label }}</h2>
    {{ $slot }}
</div>
