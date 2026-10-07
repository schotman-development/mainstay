@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

{{-- Drawn by a later part of the admin. Posting nothing, a save leaves it as
     it is; what a publish says is wrong with it is still shown here. --}}
<x-mainstay::field-section :label="$field->label()">
    <p class="text-sm text-muted">Not editable here yet.</p>
    <x-mainstay::messages :id="'field-'.$name.'-error'" :messages="$messages" />
</x-mainstay::field-section>
