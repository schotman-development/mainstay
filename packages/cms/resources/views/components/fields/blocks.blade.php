@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null, 'nested' => []])

@php
    $control = 'rounded-control px-1.5 py-0.5 text-xs text-muted hover:bg-surface hover:text-ink disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-accent';
    $items = is_array($value) ? array_values(array_filter($value, fn ($item) => $item instanceof \Mainstay\Content\Block)) : [];
@endphp

{{--
 | A list inside a block -- a FeatureGrid's Features -- in the block's
 | sidebar, the one place the admin draws a list of blocks: each item its
 | fields, moved up and down and removed, and one of each kind the list
 | holds added at its end. Each control posts the sidebar asking for that,
 | and the server draws it again. The empty value ahead of the items is how
 | a list with none left says so. `$nested` are its items', by where they
 | are in the list: `0.data.title`.
--}}
<x-mainstay::field-section :label="$field->label()">
    <input type="hidden" name="{{ $name }}" value="">

    <ol class="space-y-3">
        @foreach ($items as $index => $item)
            @php($at = "{$name}[{$index}]")
            <li class="rounded-control border border-border bg-canvas">
                <input type="hidden" name="{{ $at }}[id]" value="{{ $item->id }}">
                <input type="hidden" name="{{ $at }}[type]" value="{{ $item::handle() }}">

                <div class="flex items-center gap-0.5 border-b border-border py-1 pr-1.5 pl-3">
                    <span class="mr-auto text-xs font-medium">{{ \Mainstay\Http\Form::label($item::class) }} {{ $index + 1 }}</span>
                    <button type="button" data-block-op="up" data-list="{{ $name }}" data-index="{{ $index }}" @disabled($index === 0) class="{{ $control }}">Up</button>
                    <button type="button" data-block-op="down" data-list="{{ $name }}" data-index="{{ $index }}" @disabled($index === count($items) - 1) class="{{ $control }}">Down</button>
                    <button type="button" data-block-op="remove" data-list="{{ $name }}" data-index="{{ $index }}" class="{{ $control }}">Remove</button>
                </div>

                <x-mainstay::block-fields
                    :block="$item"
                    :prefix="$at.'[data]'"
                    :messages="collect($nested)->filter(fn ($list, $key) => str_starts_with($key, $index.'.'))->mapWithKeys(fn ($list, $key) => [substr($key, strlen((string) $index) + 1) => $list])->all()"
                />
            </li>
        @endforeach
    </ol>

    <div class="flex flex-wrap gap-2 pt-2">
        @foreach ($field->of as $kind)
            <x-mainstay::button type="button" variant="secondary" data-block-op="add" data-list="{{ $name }}" data-kind="{{ $kind::handle() }}">Add {{ \Illuminate\Support\Str::lower(\Mainstay\Http\Form::label($kind)) }}</x-mainstay::button>
        @endforeach
    </div>

    <x-mainstay::messages :id="'field-'.$name.'-error'" :messages="$messages" />
</x-mainstay::field-section>
