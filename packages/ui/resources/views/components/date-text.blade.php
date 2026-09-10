@props(['iso' => null, 'empty' => 'Not set'])

{{--
 | A date, or the absence of one.
 |
 | <time datetime> so the machine-readable value survives the formatting. An em
 | dash reads as "nothing here" to a sighted reader and as noise to a screen
 | reader, so only one of them is given it. null is a date that has not
 | happened: nothing has a release date until it has been released.
--}}
@if ($iso === null || $iso === '')
    <span aria-hidden="true">&mdash;</span>
    <span class="sr-only">{{ $empty }}</span>
@else
    @php
        /*
         | UTC is pinned twice, and both are load-bearing. A date-only string has
         | no timezone in it, so the constructor would otherwise read it as
         | midnight in the host's own zone -- and an entry released on the 3rd
         | then shows as the 2nd to anyone running east of Greenwich. The
         | setTimezone is what pins the rendering for a string that did carry an
         | offset. This is the single most repeatable way to get dates wrong
         | here, which is why there is one format rather than one per screen.
         |
         | The month names are written out rather than left to a locale: this is
         | en-GB, where September abbreviates to "Sept" and PHP's own `M` gives
         | "Sep". Spelling them keeps the admin reading the same whatever ICU
         | data a host happens to have, and needs no ext-intl to do it.
         */
        $date = (new DateTimeImmutable($iso, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];
    @endphp
    <time datetime="{{ $iso }}">{{ $date->format('j') }} {{ $months[(int) $date->format('n') - 1] }} {{ $date->format('Y') }}</time>
@endif
