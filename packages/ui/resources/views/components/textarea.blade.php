@props(['size' => 'md', 'ground' => 'canvas', 'bare' => false, 'fullWidth' => true])

{{-- resize-y rather than the browser default of both: widening a textarea past
     its field drags the whole form's column out of line. --}}
<textarea {{ $attributes->class(\Mainstay\Ui\Control::classes($size, $ground, $bare, $fullWidth).' resize-y') }}>{{ $slot }}</textarea>
