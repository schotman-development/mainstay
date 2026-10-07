@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php
    $id = 'field-'.$name;
    /* The blocks as they are stored, or, after a refused save, the text that
       was posted, so a mistake is there to correct rather than gone. */
    $json = is_string($value) ? $value : json_encode($field->serialize($value), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp

{{--
 | The page builder's content, which is edited on the site. Here only as the
 | JSON it is stored as, for repairing what the site's editor cannot open. A
 | wrong block is listed by where it is -- blocks.1.data.title -- above it.
--}}
<x-mainstay::field-section :label="$field->label()">
    <details @if ($messages) open @endif>
        <summary class="cursor-pointer text-sm text-muted hover:text-ink">Raw content</summary>
        <p id="{{ $id }}-hint" class="pt-1.5 text-xs text-muted">Edited on the site. Change it here only to repair it.</p>
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
