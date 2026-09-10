@props(['size' => 'md', 'ground' => 'canvas', 'bare' => false, 'fullWidth' => true])

{{-- The native select, which is the one control where the platform version
     beats anything rebuilt: it is the only one that becomes a proper wheel on
     a phone. --}}
<select {{ $attributes->class(\Mainstay\Ui\Control::classes($size, $ground, $bare, $fullWidth)) }}>{{ $slot }}</select>
