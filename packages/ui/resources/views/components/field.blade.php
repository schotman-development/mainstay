@props(['label', 'for', 'hint' => null, 'counter' => null, 'rail' => false])

{{--
 | One control and its label.
 |
 | `for` is the control's id, and the caller puts the same id on the control
 | inside the slot. React generated it with useId and handed it down through a
 | render prop; a Blade slot has no scope to hand anything into, so the id is
 | stated once on the field instead. The hazard useId was guarding against --
 | two fields on a screen sharing a hand-written id, leaving a screen reader
 | with one control called "Tags" and one unlabelled -- is the caller's to avoid
 | now, which in a form rendered from field declarations means the field name.
 |
 | The hint's id is derived rather than passed, so a control that needs
 | aria-describedby can name it without being told: "{{ $for }}-hint". Tied to
 | the control that way rather than just sitting under it, because a hint a
 | screen reader never reaches is a hint only some people get.
 |
 | `rail` is the narrower, tighter density of the sidebar rail against a form's.
--}}
<div class="border-b border-border {{ $rail ? 'px-4 py-3' : 'px-4 py-3.5' }}">
    <div class="flex items-baseline justify-between gap-2 pb-1.5">
        <label for="{{ $for }}" class="text-xs font-medium text-muted">{{ $label }}</label>

        {{-- Length against a soft limit, shown beside the label where it is read
             before you type rather than discovered after. Over the limit is
             information, not an error: the snippet gets truncated, nothing
             breaks. So it colours and never blocks. --}}
        @if ($counter)
            <span class="text-xs tabular-nums {{ $counter['length'] > $counter['limit'] ? 'text-danger' : 'text-muted' }}">{{ $counter['length'] }}/{{ $counter['limit'] }}</span>
        @endif
    </div>

    {{ $slot }}

    @if ($hint)
        <p id="{{ $for }}-hint" class="pt-1.5 text-xs text-muted">{{ $hint }}</p>
    @endif
</div>
