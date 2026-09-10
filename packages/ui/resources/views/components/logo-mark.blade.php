@props(['size' => 24, 'label' => null, 'lockup' => false])

@php
    $size = (int) $size;

    /*
     | The stay triangle, drawn on the design's 120-unit box with the
     | construction rule of stroke = 1/12 of the box.
     |
     | It sheds parts as it shrinks rather than scaling down uniformly, because
     | the counters close up otherwise: under 40px the mast is shortened to
     | clear the apex, under 32px the spreader bar drops, and under 20px the
     | mast goes too and only the triangle survives -- favicon size. The stroke
     | thickens as parts leave so the silhouette keeps its weight.
     |
     | The design gives four rungs -- 64, 32, 24, 16 -- and each threshold sits
     | just under its rung so all four reproduce exactly. The bottom one is 18
     | rather than 24 because the design drops the mast "at 16px", not across
     | the whole range below 24.
     |
     | The reduction ladder exists so the mark survives alone. Beside the
     | wordmark none of it applies -- the lockup already says who it is, and a
     | lone triangle next to it reads as a stray arrowhead rather than a mark.
     | So a lockup keeps the 24px geometry however small it is drawn.
     */
    $full = 'M60 24 22 100h76L60 24Z';
    $drawn = $lockup ? max($size, 24) : $size;

    [$stroke, $triangle, $mast, $spreader] = match (true) {
        $drawn < 18 => [13, 'M60 26 22 100h76L60 26Z', null, false],
        $drawn < 32 => [11, $full, 'M60 50v50', false],
        $drawn < 40 => [11, $full, 'M60 40v60', true],
        default => [10, $full, 'M60 24v76', true],
    };
@endphp

{{-- currentColor, so the mark inherits whatever text colour it sits in -- that
     is the whole of its reverse/knockout treatment.

     `label` names the mark for assistive tech. Leave it unset when the mark
     sits next to the wordmark, as it does in the lockup: the word is already
     real text, and a labelled mark beside it makes a screen reader say
     "Mainstay" twice. --}}
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 120 120" fill="none" stroke="currentColor" stroke-width="{{ $stroke }}" stroke-linejoin="miter" @if ($label) role="img" aria-label="{{ $label }}"@else aria-hidden="true"@endif {{ $attributes }}>@if ($mast)<path d="{{ $mast }}" />@endif<path d="{{ $triangle }}" />@if ($spreader)<path d="M36 70h48" />@endif</svg>
