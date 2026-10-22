@props(['id'])

{{--
 | Where the Content tab draws a selected site block's sidebar: a form of its
 | own beside the entry's rather than inside it, since a form inside a form
 | joins its inputs to the outer one and a Feature's title would post as the
 | page's. It lies over the publishing rail while a block is selected, and
 | never submits: canvas.ts fills it, posts it and keeps it.
--}}
<form id="{{ $id }}" data-canvas-sidebar aria-label="Block" hidden class="absolute inset-y-0 right-0 z-10 w-72 overflow-y-auto border-l border-border bg-surface">
    <div data-sidebar-messages role="alert" hidden class="mx-4 mt-3 space-y-1 rounded-control border border-danger/40 bg-canvas px-3 py-2 text-sm text-danger"></div>
    <div data-sidebar-body></div>
</form>
