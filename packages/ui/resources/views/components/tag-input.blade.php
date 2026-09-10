@props(['name', 'tags' => [], 'placeholder' => 'Add a tag', 'id' => null, 'describedBy' => null])

{{--
 | Tags as chips with a text input after them. Enter and comma both commit,
 | because both are what people already type; Backspace on an empty input takes
 | the last one back, which is the one thing a chip list is always missing.
 | Case-insensitively deduplicated, so "Editor" typed after "editor" does not
 | quietly create a second tag that filters to a different set of entries.
 |
 | React held the list in state and handed it back through onChange. Here the
 | chips are real markup and each carries a hidden input, so the field posts
 | with the form it sits in and needs nothing to serialise it.
 |
 | The chip is written once, in the <template> below: the bundle clones it to
 | add one rather than building the markup a second time in JavaScript, which is
 | how the two would drift.
--}}
<x-mainstay::input-shell data-tag-input class="flex flex-wrap items-center gap-1.5 px-2 py-1.5">
    @foreach ($tags as $tag)
        <x-mainstay::tag-chip :name="$name" :tag="$tag" />
    @endforeach

    <x-mainstay::input
        bare
        :id="$id"
        :aria-describedby="$describedBy"
        data-tag-field
        :placeholder="count($tags) === 0 ? $placeholder : ''"
        :data-placeholder="$placeholder"
        :full-width="false"
        class="min-w-24 flex-1 py-0.5 text-sm placeholder:text-muted/70"
    />

    <template data-tag-template>
        <x-mainstay::tag-chip :name="$name" tag="" />
    </template>
</x-mainstay::input-shell>
