@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php
    $id = 'field-'.$name;
    /* The document as JSON, which the editor reads and writes back, or, after
       a refused save, the JSON that was posted. */
    $json = is_string($value) ? $value : ($value === null ? '' : json_encode($field->serialize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $document = is_string($value) ? new \Mainstay\Content\Document((array) json_decode($value, true)) : $value;
    $tool = 'rounded-control px-2 py-1 text-sm text-muted hover:bg-surface hover:text-ink aria-pressed:bg-surface aria-pressed:text-ink disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-accent';
@endphp

{{--
 | The document rendered read-only, and the hidden input posting it as it was
 | drawn: all a browser without JavaScript gets. The editor takes the rendered
 | element over, shows the toolbar, and writes every change into the input.
--}}
<x-mainstay::field-section :label="$field->label()">
    <div data-rich-text data-label="{{ $field->label() }}" class="rounded-control border border-border bg-canvas focus-within:border-accent">
        <input type="hidden" name="{{ $name }}" value="{{ $json }}">

        <div data-rich-text-toolbar role="group" aria-label="Formatting" hidden class="flex flex-wrap items-center gap-0.5 border-b border-border px-1.5 py-1">
            <select data-command="block" aria-label="Text style" class="rounded-control bg-transparent px-1.5 py-1 text-sm text-ink hover:bg-surface">
                <option value="paragraph">Paragraph</option>
                <option value="heading-2">Heading 2</option>
                <option value="heading-3">Heading 3</option>
                <option value="heading-4">Heading 4</option>
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

@once
    @push('dialogs')
        {{-- One for every editor on the page, outside the entry's form so its
             own can submit: whichever editor opened it answers it. --}}
        <x-mainstay::dialog id="mainstay-link" label="Link">
            <form method="dialog" class="space-y-4">
                <div>
                    <label for="mainstay-link-href" class="block pb-1.5 text-xs font-medium text-muted">Address</label>
                    <x-mainstay::input id="mainstay-link-href" name="href" autocomplete="off" autofocus placeholder="https://, mailto:, tel: or /a-path" aria-describedby="mainstay-link-error" />
                    <p id="mainstay-link-error" data-link-error hidden class="pt-1.5 text-xs text-danger">A link leads to a web, mail or phone address, or a path on this site.</p>
                </div>
                {{-- Apply first, since Enter in the address submits with the
                     form's first submit button; drawn last, where it reads. --}}
                <div class="flex flex-row-reverse gap-2">
                    <x-mainstay::button type="submit" value="apply">Apply</x-mainstay::button>
                    <x-mainstay::button type="button" variant="secondary" data-dialog-close>Cancel</x-mainstay::button>
                    <x-mainstay::button type="submit" variant="secondary" value="remove" formnovalidate class="mr-auto">Remove link</x-mainstay::button>
                </div>
            </form>
        </x-mainstay::dialog>
    @endpush
@endonce
