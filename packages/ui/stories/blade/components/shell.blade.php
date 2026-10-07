@props(['current', 'breadcrumb' => [], 'actions' => null])

{{-- The shipped shell around the workshop's bar and navigation, so a screen
     story is drawn in the frame the admin draws it in. --}}
<x-mainstay::shell :breadcrumb="$breadcrumb" :actions="$actions">
    <x-slot:bar><x-stories::top-bar /></x-slot:bar>
    <x-slot:navigation><x-stories::demo-sidebar :current="$current" /></x-slot:navigation>

    {{ $slot }}
</x-mainstay::shell>
