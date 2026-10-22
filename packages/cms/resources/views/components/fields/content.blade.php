@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php
    $id = 'field-'.$name;
    /* The content as it is stored, or, after a refused save, the text that
       was posted, so a mistake is there to correct rather than gone. */
    $json = is_string($value) ? $value : ($value === null ? '' : json_encode($field->serialize($value), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
@endphp

{{--
 | A type with a page holds its content as the JSON it is stored as, its text
 | and its blocks, a wrong block listed by where it is --
 | content.content.1.attrs.data.title -- above it. A type with none holds text
 | alone, edited as any rich text is.
--}}
@if ($field->hasPage())
    <x-mainstay::field-section :label="$field->label()">
        <details @if ($messages) open @endif>
            <summary class="cursor-pointer text-sm text-muted hover:text-ink">Raw content</summary>
            <p id="{{ $id }}-hint" class="pt-1.5 text-xs text-muted">The page's text and blocks as they are stored.</p>
            <x-mainstay::messages :id="$id.'-error'" :messages="$messages" />
            <x-mainstay::textarea
                :id="$id"
                :name="$name"
                rows="16"
                spellcheck="false"
                :aria-label="$field->label().' as JSON'"
                :aria-invalid="$messages ? 'true' : null"
                :aria-describedby="$messages ? $id.'-error' : $id.'-hint'"
                class="mt-2 font-mono text-xs"
            >{{ $json }}</x-mainstay::textarea>
        </details>
    </x-mainstay::field-section>
@else
    <x-mainstay::fields.rich-text :field="$field" :name="$name" :value="$value" :messages="$messages" :hint="$hint" />
@endif
