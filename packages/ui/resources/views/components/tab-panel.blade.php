@props(['id'])

{{-- One tab's panel, named by the tab that shows it. --}}
<div id="{{ $id }}" role="tabpanel" aria-labelledby="{{ $id }}-tab" {{ $attributes }}>{{ $slot }}</div>
