@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php($id = 'field-'.$name)

{{-- One line, held to the field's max by the browser as well as the rules. --}}
<x-mainstay::field :label="$field->label()" :for="$id" :hint="$hint">
    <x-mainstay::input
        type="text"
        :id="$id"
        :name="$name"
        :value="$value"
        :maxlength="$field->max"
        :aria-invalid="$messages ? 'true' : null"
        :aria-describedby="$messages ? $id.'-error' : ($hint ? $id.'-hint' : null)"
        {{ $attributes }}
    />
    <x-mainstay::messages :id="$id.'-error'" :messages="$messages" />
</x-mainstay::field>
