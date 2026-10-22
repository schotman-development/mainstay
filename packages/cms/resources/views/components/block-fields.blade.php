@props(['block', 'prefix', 'messages' => []])

{{--
 | The fields of a block, or of an item in a list inside one, as its sidebar
 | draws them: each by the component Details draws its kind with, posting
 | under `$prefix` -- `data[headline]`, `data[items][0][data][title]`. Text
 | is marked with the item's id and the field's name, as @field marks it on
 | the page, so typing in either reaches the other. `$messages` are by where
 | they are in the item: `data.headline`, `data.items.0.data.title`.
--}}
@foreach (\Mainstay\Facades\Mainstay::fields($block::class) as $name => $field)
    @php
        $input = "{$prefix}[{$name}]";
        $at = "data.{$name}";
        $value = $block->{$name} ?? null;
        $own = $messages[$at] ?? [];
    @endphp

    @if ($field instanceof \Mainstay\Fields\Blocks)
        <x-dynamic-component
            :component="$field->component()"
            :field="$field"
            :name="$input"
            :value="$value"
            :messages="$own"
            :nested="collect($messages)->filter(fn ($list, $key) => str_starts_with($key, $at.'.'))->mapWithKeys(fn ($list, $key) => [substr($key, strlen($at) + 1) => $list])->all()"
        />
    @elseif ($field instanceof \Mainstay\Fields\Relation)
        <x-dynamic-component
            :component="$field->component()"
            :field="$field"
            :name="$input"
            :value="$value"
            :messages="$own"
            :search="route('mainstay.relations', [request()->route('type'), $block::handle().'.'.$name, 'locale' => \Mainstay\Http\Form::locale(request())])"
        />
    @elseif ($field instanceof \Mainstay\Fields\Text || $field instanceof \Mainstay\Fields\Textarea)
        <x-dynamic-component :component="$field->component()" :field="$field" :name="$input" :value="$value" :messages="$own" :data-mainstay-item="$block->id" :data-mainstay-field="$name" />
    @else
        <x-dynamic-component :component="$field->component()" :field="$field" :name="$input" :value="$value" :messages="$own" />
    @endif
@endforeach
