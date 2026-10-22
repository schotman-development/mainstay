@props(['block', 'prefix', 'messages' => []])

{{--
 | The fields of a block, as its settings draw them: each by the component
 | Details draws its kind with, posting under `$prefix` -- `data[headline]`.
 | Text is marked with the block's id and the field's name, as @field marks
 | it on the page, so typing in either reaches the other. `$messages` are by
 | where they are in the block: `data.headline`.
--}}
@foreach (\Mainstay\Facades\Mainstay::fields($block::class) as $name => $field)
    @php
        $input = "{$prefix}[{$name}]";
        $at = "data.{$name}";
        $value = $block->{$name} ?? null;
        $own = $messages[$at] ?? [];
    @endphp

    @if ($field instanceof \Mainstay\Fields\Relation)
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
