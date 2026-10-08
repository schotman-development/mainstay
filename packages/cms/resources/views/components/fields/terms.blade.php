@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php
    $id = 'field-'.$name;
    [$chips, $terms] = \Mainstay\Http\Form::chips($field, $value, \Mainstay\Http\Form::locale(request()));
@endphp

{{--
 | The tag input, suggesting the taxonomy's terms in the form's language. A
 | chip matching a term's title posts its id, `id:5`; one matching none posts
 | `new:` and its text, a term the save creates.
--}}
<x-mainstay::field :label="$field->label()" :for="$id" :hint="$hint">
    <input type="hidden" name="{{ $name }}[]" value="">
    <x-mainstay::tag-input :id="$id" :name="$name" :tags="$chips" :list="$id.'-terms'" new="new:" :described-by="$messages ? $id.'-error' : null" />
    <datalist id="{{ $id }}-terms">
        @foreach ($terms as $posted => $title)
            <option value="{{ $title }}" data-value="{{ $posted }}"></option>
        @endforeach
    </datalist>
    <x-mainstay::messages :id="$id.'-error'" :messages="$messages" />
</x-mainstay::field>
