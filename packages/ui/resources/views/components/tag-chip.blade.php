@props(['name', 'tag'])

{{-- One chip: the label a reader sees, the hidden input the form posts, and the
     button that takes it back off. Cloned out of the tag input's <template> to
     add one, so this markup is stated once. --}}
<span data-tag class="inline-flex items-center gap-1 rounded-control border border-border bg-surface py-0.5 pl-2 pr-1 text-xs">
    <span data-tag-label>{{ $tag }}</span>
    <input type="hidden" name="{{ $name }}[]" value="{{ $tag }}">

    <button type="button" data-tag-remove class="rounded-control px-0.5 text-muted hover:text-danger focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent">
        <span aria-hidden="true">&times;</span>
        {{-- Every remove button is an identical glyph; without this a screen
             reader gets a row of buttons called "x". --}}
        <span class="sr-only">Remove <span data-tag-label>{{ $tag }}</span></span>
    </button>
</span>
