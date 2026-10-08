@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php
    $locale = \Mainstay\Http\Form::locale(request());
    $rows = \Mainstay\Http\Form::rows($field, $value, $locale);
    $many = $field->many();
    $input = $many ? $name.'[]' : $name;
    $control = 'rounded-control px-1.5 py-0.5 text-xs text-muted hover:bg-surface hover:text-ink focus-visible:outline-2 focus-visible:outline-accent';
    $search = route('mainstay.relations', [request()->route('type'), $name, 'locale' => $locale]);
@endphp

{{--
 | The chosen entries as rows, each posting its one hidden input, so the
 | order posted is the rows' order. Reordered by dragging or by Up and Down,
 | removed by Remove, and added from the search under them. The empty value
 | ahead of the rows is how a list with none left says so.
--}}
<x-mainstay::field-section :label="$field->label()">
    <div data-relation data-many="{{ $many ? 'true' : 'false' }}" data-search="{{ $search }}" class="space-y-2">
        <input type="hidden" name="{{ $input }}" value="">

        <ol data-relation-rows class="space-y-1">
            @foreach ($rows as $row)
                @include('mainstay::relation-row', $row)
            @endforeach
        </ol>

        <template data-relation-template>
            @include('mainstay::relation-row', ['value' => '', 'title' => '', 'type' => $field->several() ? '' : null])
        </template>

        <div class="relative">
            <x-mainstay::input type="search" size="sm" data-relation-query autocomplete="off" :placeholder="$many ? 'Add one…' : 'Choose one…'" :aria-label="'Search for '.strtolower($field->label())" />
            <ul data-relation-results class="mt-1 space-y-0.5"></ul>
        </div>
    </div>
    <x-mainstay::messages :id="'field-'.$name.'-error'" :messages="$messages" />
</x-mainstay::field-section>
