@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php($id = 'field-'.$name)

<x-mainstay::field :label="$field->label()" :for="$id" :hint="$hint">
    <x-mainstay::textarea
        :id="$id"
        :name="$name"
        rows="4"
        :aria-invalid="$messages ? 'true' : null"
        :aria-describedby="$messages ? $id.'-error' : ($hint ? $id.'-hint' : null)"
        {{ $attributes }}
    >{{ $value }}</x-mainstay::textarea>
    <x-mainstay::messages :id="$id.'-error'" :messages="$messages" />
</x-mainstay::field>
