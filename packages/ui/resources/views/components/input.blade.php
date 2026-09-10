@props(['size' => 'md', 'ground' => 'canvas', 'bare' => false, 'fullWidth' => true])

{{-- Every single-line control: text, search, date, whatever `type` says. Native
     rather than rebuilt, so a date field brings its own picker, its own keyboard
     handling and its own locale formatting, and a search field brings its own
     clear button. --}}
<input {{ $attributes->class(\Mainstay\Ui\Control::classes($size, $ground, $bare, $fullWidth)) }}>
