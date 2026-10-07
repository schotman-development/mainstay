@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

{{-- Drawn by a later part of the admin. Posting nothing, a save leaves it as
     it is. --}}
<x-mainstay::field-section :label="$field->label()">
    <p class="text-sm text-muted">Not editable here yet.</p>
</x-mainstay::field-section>
