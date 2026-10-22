@props(['url', 'blocks' => []])

@php
    $tool = 'rounded-control px-2 py-1 text-sm text-muted hover:bg-surface hover:text-ink aria-pressed:bg-surface aria-pressed:text-ink disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-accent';
    $item = 'block w-full rounded-control px-3 py-1.5 text-left text-sm text-ink hover:bg-surface focus-visible:bg-surface focus-visible:outline-none';
    $text = ['paragraph' => 'Paragraph', 'heading' => 'Heading', 'blockquote' => 'Quote', 'code_block' => 'Code block', 'bullet_list' => 'Bulleted list', 'ordered_list' => 'Numbered list', 'horizontal_rule' => 'Separator'];
@endphp

{{--
 | The Content tab: the page the site draws from the form, in a frame the
 | admin drives, posted to `$url` each time the tab opens after Details
 | changed. Every control is out here, around the frame: the toolbar, the
 | menu of blocks to add, and the grip a block is dragged by. The frame holds
 | the page alone, and no script runs in it. canvas.ts in packages/editor is
 | its behaviour; without it the tab holds this message.
--}}
<div data-canvas data-canvas-url="{{ $url }}" data-blocks="{{ json_encode((object) $blocks) }}" class="relative flex min-h-0 flex-1 flex-col">
    <div data-canvas-toolbar role="toolbar" aria-label="Content" hidden class="flex shrink-0 flex-wrap items-center gap-0.5 border-b border-border px-2 py-1">
        <button type="button" data-command="undo" class="{{ $tool }}">Undo</button>
        <button type="button" data-command="redo" class="{{ $tool }}">Redo</button>
        <span aria-hidden="true" class="mx-1 h-5 w-px bg-border"></span>
        <button type="button" data-command="strong" aria-label="Bold" class="{{ $tool }} font-bold">B</button>
        <button type="button" data-command="em" aria-label="Italic" class="{{ $tool }} italic">I</button>
        <button type="button" data-command="code" aria-label="Code" class="{{ $tool }} font-mono">&lt;/&gt;</button>
        <button type="button" data-command="link" class="{{ $tool }}">Link</button>
        <span aria-hidden="true" class="mx-1 h-5 w-px bg-border"></span>
        <button type="button" data-command="add" aria-haspopup="true" aria-expanded="false" aria-controls="canvas-menu" class="{{ $tool }}">Add block</button>
        <select data-command="turn" aria-label="Turn into" class="rounded-control bg-transparent px-1.5 py-1 text-sm text-ink hover:bg-surface disabled:opacity-40">
            <option value="paragraph">Paragraph</option>
            @foreach (range(1, 6) as $level)
                <option value="heading-{{ $level }}">Heading {{ $level }}</option>
            @endforeach
            <option value="blockquote">Quote</option>
            <option value="code_block">Code block</option>
            <option value="bullet_list">Bulleted list</option>
            <option value="ordered_list">Numbered list</option>
            {{-- What the current block is when it is none of these: a site
                 block, a separator. --}}
            <option value="" hidden></option>
        </select>
        <button type="button" data-command="up" class="{{ $tool }}">Move up</button>
        <button type="button" data-command="down" class="{{ $tool }}">Move down</button>
        <button type="button" data-command="duplicate" class="{{ $tool }}">Duplicate</button>
        <button type="button" data-command="delete" class="{{ $tool }}">Delete</button>
    </div>

    <p data-canvas-message role="status" class="m-6 rounded-control border border-border bg-surface px-3 py-2 text-sm">The page is edited with JavaScript, which is off. The content is in Details, as raw data.</p>

    <div data-canvas-stage hidden class="relative flex min-h-0 flex-1">
        <div class="relative w-8 shrink-0 border-r border-border bg-surface">
            <button type="button" data-canvas-grip draggable="true" hidden aria-label="Drag to move the block" title="Drag to move the block" class="absolute left-1 grid size-6 cursor-grab place-items-center rounded-control text-muted hover:bg-canvas hover:text-ink">
                <x-mainstay::path-icon d="M6 4h.01M10 4h.01M6 8h.01M10 8h.01M6 12h.01M10 12h.01" class="size-4" stroke-width="2.5" />
            </button>
        </div>
        <iframe data-canvas-frame title="The page" sandbox="allow-same-origin" class="min-h-0 min-w-0 flex-1 border-0 bg-white"></iframe>
        <div data-canvas-line hidden class="pointer-events-none absolute right-0 left-8 h-0.5 bg-accent"></div>
    </div>

    <div id="canvas-menu" data-canvas-menu role="group" aria-label="Add a block" hidden class="absolute z-20 max-h-80 w-56 overflow-y-auto rounded-control border border-border bg-canvas p-1 shadow-lg">
        <p class="px-3 pt-1.5 pb-1 text-xs font-medium text-muted">Text</p>
        @foreach ($text as $type => $label)
            <button type="button" data-insert="{{ $type }}" class="{{ $item }}">{{ $label }}</button>
        @endforeach
        @if ($blocks !== [])
            <p class="px-3 pt-2.5 pb-1 text-xs font-medium text-muted">Blocks</p>
            @foreach ($blocks as $handle => $label)
                <button type="button" data-insert="block:{{ $handle }}" class="{{ $item }}">{{ $label }}</button>
            @endforeach
        @endif
    </div>
</div>

<x-mainstay::link-dialog />
