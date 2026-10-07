@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php($id = 'field-'.$name)

{{-- A blank first option where nothing need be chosen, or nothing has been:
     a required select drawn on its first option would look chosen when the
     entry holds nothing. --}}
<x-mainstay::field :label="$field->label()" :for="$id" :hint="$hint">
    <x-mainstay::select
        :id="$id"
        :name="$name"
        :aria-invalid="$messages ? 'true' : null"
        :aria-describedby="$messages ? $id.'-error' : ($hint ? $id.'-hint' : null)"
        {{ $attributes }}
    >
        @if (! $field->isRequired() || blank($value))
            <option value="">—</option>
        @endif
        @foreach ($field->options() as $option => $label)
            <option value="{{ $option }}" @selected((string) $value === (string) $option)>{{ $label }}</option>
        @endforeach
    </x-mainstay::select>
    <x-mainstay::messages :id="$id.'-error'" :messages="$messages" />
</x-mainstay::field>
