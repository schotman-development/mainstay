@props(['size' => 24, 'label' => null])

{{-- The sail, the same drawing the Mainstay website carries in its header.

     currentColor, so the mark inherits whatever text colour it sits in -- that
     is the whole of its reverse/knockout treatment.

     `label` names the mark for assistive tech. Leave it unset when the mark
     sits next to the wordmark, as it does in the lockup: the word is already
     real text, and a labelled mark beside it makes a screen reader say
     "Mainstay" twice. --}}
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" @if ($label) role="img" aria-label="{{ $label }}"@else aria-hidden="true"@endif {{ $attributes }}><path d="M11 2v19M11 4 4 18h7" /><path d="M13 6.5 20 18h-7z" fill="currentColor" stroke="none" /><path d="M3 21.5h18" /></svg>
