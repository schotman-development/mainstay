@props(['align' => 'start'])

<x-mainstay::dropdown :align="$align">
    <x-slot:label>New</x-slot:label>

    <x-mainstay::dropdown-item>Page</x-mainstay::dropdown-item>
    <x-mainstay::dropdown-item>Collection</x-mainstay::dropdown-item>
    <x-mainstay::dropdown-item>Media upload</x-mainstay::dropdown-item>
</x-mainstay::dropdown>
