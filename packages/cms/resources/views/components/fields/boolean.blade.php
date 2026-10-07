@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php($id = 'field-'.$name)

{{-- A box left unticked posts nothing, so the hidden 0 before it is what an
     unticked box says. `boolean` refuses the "on" a bare box posts. --}}
<x-mainstay::field :label="$field->label()" :for="$id" :hint="$hint">
    <input type="hidden" name="{{ $name }}" value="0">
    <x-mainstay::checkbox
        :id="$id"
        :name="$name"
        value="1"
        :checked="filter_var($value, FILTER_VALIDATE_BOOL)"
        :label="$field->label()"
        :aria-invalid="$messages ? 'true' : null"
        :aria-describedby="$messages ? $id.'-error' : ($hint ? $id.'-hint' : null)"
        {{ $attributes }}
    />
    <x-mainstay::messages :id="$id.'-error'" :messages="$messages" />
</x-mainstay::field>
