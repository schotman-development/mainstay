@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php($id = 'field-'.$name)

{{-- Whole numbers step by one; a float takes any step. --}}
<x-mainstay::field :label="$field->label()" :for="$id" :hint="$hint">
    <x-mainstay::input
        type="number"
        :id="$id"
        :name="$name"
        :value="$value"
        :step="$field->phpType === 'int' ? 1 : 'any'"
        :min="$field->min"
        :max="$field->max"
        :aria-invalid="$messages ? 'true' : null"
        :aria-describedby="$messages ? $id.'-error' : ($hint ? $id.'-hint' : null)"
        {{ $attributes }}
    />
    <x-mainstay::messages :id="$id.'-error'" :messages="$messages" />
</x-mainstay::field>
