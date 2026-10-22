@props(['url', 'block' => null, 'sidebar' => null, 'header' => null, 'blocks' => [], 'top' => [], 'invalid' => [], 'pictures' => false])

@php
    $tool = 'grid h-8 min-w-8 place-items-center rounded-control px-1.5 text-sm text-muted hover:bg-surface hover:text-ink aria-pressed:bg-surface aria-pressed:text-ink disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-accent';
    $item = 'block w-full rounded-control px-3 py-1.5 text-left text-sm text-ink hover:bg-surface focus-visible:bg-surface focus-visible:outline-none';
    $text = ['paragraph' => 'Paragraph', 'heading' => 'Heading', 'blockquote' => 'Quote', 'code_block' => 'Code block', 'bullet_list' => 'Bulleted list', 'ordered_list' => 'Numbered list', 'horizontal_rule' => 'Separator'];
    $rule = '<span aria-hidden="true" class="mx-0.5 h-5 w-px bg-border"></span>';
@endphp

{{--
 | The Content tab: the page the site draws from the form, in a frame the
 | admin drives, posted to `$url` each time the tab opens after Details
 | changed. Every control is out here, over the frame: the toolbar floating
 | by the current block, as Gutenberg's does, which it is dragged by too;
 | Undo, Redo and Add block in `$header`, at the end of the tabs' bar; and
 | the menu of blocks to add. The frame holds the page alone, and no script
 | runs in it. A site block selected there is posted to `$block`, and its
 | settings are in the dialog whose form `$sidebar` names, outside the
 | entry's. `$blocks` is every block the content can hold, by handle, its
 | label and the blocks it holds inside it; `$top` the ones at its top
 | level. An image a block prints is replaced and edited on the page, in
 | the library's dialogs. After a save refused for a block's field, its
 | errors are listed here, naming the block. canvas.ts in packages/editor is
 | its behaviour; without it the tab holds this message.
--}}
<div data-canvas data-canvas-url="{{ $url }}" data-block-url="{{ $block }}" data-sidebar="{{ $sidebar }}" data-header="{{ $header }}" data-blocks="{{ json_encode((object) $blocks) }}" data-top="{{ json_encode(array_values($top)) }}" data-invalid="{{ json_encode((object) $invalid) }}" @if ($pictures) data-picker="{{ route('mainstay.media', ['pick' => 1]) }}" data-media="{{ route('mainstay.media') }}" @endif class="relative flex min-h-0 flex-1 flex-col">
    @if ($invalid !== [])
        <ul data-canvas-invalid role="alert" class="mx-3 mt-3 space-y-1 rounded-control border border-danger/40 bg-surface px-3 py-2 text-sm text-danger">
            @foreach ($invalid as $id => $each)
                @foreach (\Illuminate\Support\Arr::flatten($each['messages']) as $message)
                    <li data-invalid-block="{{ $id }}">{{ $each['label'] }}: {{ $message }}</li>
                @endforeach
            @endforeach
        </ul>
    @endif

    <p data-canvas-message role="status" class="m-6 rounded-control border border-border bg-surface px-3 py-2 text-sm">The page is edited with JavaScript, which is off. The content is in Details, as raw data.</p>

    <div data-canvas-stage hidden class="relative flex min-h-0 flex-1 overflow-hidden">
        <iframe data-canvas-frame title="The page" sandbox="allow-same-origin" class="min-h-0 min-w-0 flex-1 border-0 bg-white"></iframe>
        <div data-canvas-line hidden class="pointer-events-none absolute bg-accent"></div>

        <div data-canvas-toolbar role="toolbar" aria-label="Block" hidden class="absolute z-10 flex items-center gap-0.5 rounded-control border border-border bg-canvas p-0.5 shadow-md">
            <button type="button" data-canvas-grip draggable="true" aria-label="Drag to move the block" title="Drag to move the block" class="{{ $tool }} cursor-grab">
                <x-mainstay::path-icon d="M6 4h.01M10 4h.01M6 8h.01M10 8h.01M6 12h.01M10 12h.01" class="size-4" stroke-width="2.5" />
            </button>
            <button type="button" data-command="up" aria-label="Move up" title="Move up" class="{{ $tool }}"><x-mainstay::path-icon d="M8 13V3M4 7l4-4 4 4" /></button>
            <button type="button" data-command="down" aria-label="Move down" title="Move down" class="{{ $tool }}"><x-mainstay::path-icon d="M8 3v10M4 9l4 4 4-4" /></button>
            {!! $rule !!}
            <span data-canvas-text class="flex items-center gap-0.5">
                <select data-command="turn" aria-label="Turn into" class="h-8 rounded-control bg-transparent px-1.5 text-sm text-ink hover:bg-surface disabled:opacity-40">
                    <option value="paragraph">Paragraph</option>
                    @foreach (range(1, 6) as $level)
                        <option value="heading-{{ $level }}">Heading {{ $level }}</option>
                    @endforeach
                    <option value="blockquote">Quote</option>
                    <option value="code_block">Code block</option>
                    <option value="bullet_list">Bulleted list</option>
                    <option value="ordered_list">Numbered list</option>
                    {{-- What the current block is when it is none of these: a
                         separator. --}}
                    <option value="" hidden></option>
                </select>
                <button type="button" data-command="strong" aria-label="Bold" title="Bold" class="{{ $tool }} font-bold">B</button>
                <button type="button" data-command="em" aria-label="Italic" title="Italic" class="{{ $tool }} italic">I</button>
                <button type="button" data-command="code" aria-label="Code" title="Code" class="{{ $tool }} font-mono">&lt;/&gt;</button>
                <button type="button" data-command="link" class="{{ $tool }}">Link</button>
                {!! $rule !!}
            </span>
            <span data-canvas-image hidden class="flex items-center gap-0.5">
                <button type="button" data-command="replace" class="{{ $tool }}">Replace image</button>
                <button type="button" data-command="image" class="{{ $tool }}">Edit image</button>
                {!! $rule !!}
            </span>
            <button type="button" data-command="settings" aria-label="Block settings" title="Block settings" aria-haspopup="dialog" class="{{ $tool }}"><x-mainstay::path-icon d="M3 2.5v11M8 2.5v11M13 2.5v11M1.5 6h3M6.5 10h3M11.5 5h3" /></button>
            <button type="button" data-command="duplicate" aria-label="Duplicate" title="Duplicate" class="{{ $tool }}"><x-mainstay::path-icon d="M5.5 5.5h8v8h-8zM3 10.5h-.5v-8h8V3" /></button>
            <button type="button" data-command="delete" aria-label="Delete" title="Delete" class="{{ $tool }}"><x-mainstay::path-icon d="M2.5 4h11M6 4V2.5h4V4M4 4l.5 9.5h7L12 4" /></button>
        </div>
    </div>

    <div id="canvas-menu" data-canvas-menu role="group" aria-label="Add a block" hidden class="absolute z-20 max-h-80 w-56 overflow-y-auto rounded-control border border-border bg-canvas p-1 shadow-lg">
        <div data-menu-group>
            <p class="px-3 pt-1.5 pb-1 text-xs font-medium text-muted">Text</p>
            @foreach ($text as $type => $label)
                <button type="button" data-insert="{{ $type }}" class="{{ $item }}">{{ $label }}</button>
            @endforeach
        </div>
        {{-- Every block the content holds, at its top level or inside
             another: canvas.ts shows the ones that go where it opens. --}}
        @if ($blocks !== [])
            <div data-menu-group>
                <p class="px-3 pt-2.5 pb-1 text-xs font-medium text-muted">Blocks</p>
                @foreach ($blocks as $handle => $each)
                    <button type="button" data-insert="block:{{ $handle }}" class="{{ $item }}">{{ $each['label'] }}</button>
                @endforeach
            </div>
        @endif
    </div>
</div>

<x-mainstay::link-dialog />

@if ($pictures)
    <x-mainstay::picker-dialog />

    @push('dialogs')
        <x-mainstay::dialog id="mainstay-image" label="Edit image" wide>
            <div data-image-body aria-live="polite" class="max-h-[70vh] overflow-y-auto"></div>
        </x-mainstay::dialog>
    @endpush
@endif
