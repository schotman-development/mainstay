@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php
    $id = 'field-'.$name;
    /* The document as JSON, which the editor reads and writes back, or, after
       a refused save, the JSON that was posted. */
    $json = is_string($value) ? $value : ($value === null ? '' : json_encode($field->serialize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $document = is_string($value) ? new \Mainstay\Content\Document((array) json_decode($value, true)) : $value;
    $style = app(\Mainstay\Mainstay::class)->editorStyle();
    $tool = 'rounded-control px-2 py-1 text-sm text-muted hover:bg-surface hover:text-ink aria-pressed:bg-surface aria-pressed:text-ink disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-accent';
@endphp

{{--
 | The document rendered read-only, and the hidden input posting it as it was
 | drawn: all a browser without JavaScript gets. The editor takes the rendered
 | element over, shows the toolbar, and writes every change into the input --
 | in a frame of the site's stylesheets, where the site names them.
--}}
<x-mainstay::field-section :label="$field->label()">
    <div data-rich-text data-label="{{ $field->label() }}" @if ($style) data-stylesheets="{{ json_encode($style['stylesheets'], JSON_UNESCAPED_SLASHES) }}" data-class="{{ $style['class'] }}" @endif class="rounded-control border border-border bg-canvas focus-within:border-accent">
        <input type="hidden" name="{{ $name }}" value="{{ $json }}">

        <div data-rich-text-toolbar role="group" aria-label="Formatting" hidden class="flex flex-wrap items-center gap-0.5 border-b border-border px-1.5 py-1">
            <select data-command="block" aria-label="Text style" class="rounded-control bg-transparent px-1.5 py-1 text-sm text-ink hover:bg-surface">
                <option value="paragraph">Paragraph</option>
                @foreach (range(1, 6) as $level)
                    <option value="heading-{{ $level }}">Heading {{ $level }}</option>
                @endforeach
            </select>
            <button type="button" data-command="strong" aria-label="Bold" class="{{ $tool }} font-bold">B</button>
            <button type="button" data-command="em" aria-label="Italic" class="{{ $tool }} italic">I</button>
            <button type="button" data-command="code" aria-label="Code" class="{{ $tool }} font-mono">&lt;/&gt;</button>
            <button type="button" data-command="link" class="{{ $tool }}">Link</button>
            <button type="button" data-command="bullet_list" class="{{ $tool }}">Bullets</button>
            <button type="button" data-command="ordered_list" class="{{ $tool }}">Numbers</button>
            <button type="button" data-command="blockquote" class="{{ $tool }}">Quote</button>
            <button type="button" data-command="code_block" class="{{ $tool }}">Code block</button>
            <button type="button" data-command="horizontal_rule" class="{{ $tool }}">Rule</button>
        </div>

        <div data-rich-text-body @if ($messages) aria-describedby="{{ $id }}-error" @endif class="min-h-32 px-3 py-2 text-sm">{{ $document }}</div>
    </div>
    <x-mainstay::messages :id="$id.'-error'" :messages="$messages" />
</x-mainstay::field-section>

<x-mainstay::link-dialog />
