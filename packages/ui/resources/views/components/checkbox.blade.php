@props(['indeterminate' => false, 'label'])

{{--
 | A checkbox.
 |
 | `label` is required rather than optional: every checkbox in the admin is a
 | bare box in a table cell with no visible label beside it, so there is nothing
 | else for a screen reader to read.
 |
 | Part-checked -- some of what this box covers is selected, not all -- is a
 | state the DOM has and no markup can reach: `indeterminate` is a property with
 | no attribute behind it. It is marked here and set by the admin's bundle,
 | which walks [data-indeterminate] once on load. Without it a part-selected
 | page shows an empty box, which reads as "nothing here is selected" when the
 | truth is the opposite.
--}}
<input type="checkbox"@if ($indeterminate) data-indeterminate @endif {{ $attributes->merge(['aria-label' => $label])->class([
        'size-4 accent-accent align-middle',
        'disabled:opacity-40',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
    ]) }}>
