@props(['name', 'tags' => [], 'placeholder' => 'Add a tag', 'id' => null, 'describedBy' => null, 'list' => null, 'new' => null])

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
 |
 | Given a <datalist> as `list`, it suggests from it, and a tag typed as one
 | of its options posts that option's `data-value` -- a term's id, say -- in
 | place of its text; one matching none posts its text after `new`. A tag is
 | a string, or a label and what it posts.
--}}
<x-mainstay::input-shell data-tag-input :data-tag-new="$new" class="flex flex-wrap items-center gap-1.5 px-2 py-1.5">
    @foreach ($tags as $tag)
        <x-mainstay::tag-chip :name="$name" :tag="is_array($tag) ? $tag[0] : $tag" :value="is_array($tag) ? $tag[1] : $tag" />
    @endforeach

    <x-mainstay::input
        bare
        :id="$id"
        :aria-describedby="$describedBy"
        data-tag-field
        :list="$list"
        :placeholder="count($tags) === 0 ? $placeholder : ''"
        :data-placeholder="$placeholder"
        :full-width="false"
        class="min-w-24 flex-1 py-0.5 text-sm placeholder:text-muted/70"
    />

    <template data-tag-template>
        <x-mainstay::tag-chip :name="$name" tag="" />
    </template>
</x-mainstay::input-shell>
