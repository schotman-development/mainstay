@props(['tabs', 'label', 'name'])

{{--
 | Tabs over panels, as the ARIA pattern has them: a tablist of buttons, the
 | arrow keys between them, and the chosen one's panel alone shown. `$tabs`
 | is each tab's label by the id of the panel it shows, each panel a
 | <x-mainstay::tab-panel> of that id. The bar is drawn hidden and every panel
 | open, so without JavaScript the panels read one after the other; tabs.ts
 | shows the bar, and the first tab's panel alone. `$name` keeps the tab
 | chosen across a reload and a save. As tall as the shell's breadcrumb bar
 | above it -- a control's 2.125rem, its padding and its border -- each label
 | centred in it.
--}}
<div data-tabs="{{ $name }}" role="tablist" aria-label="{{ $label }}" hidden {{ $attributes->class('flex h-[calc(2.125rem+1.5rem+1px)] shrink-0 gap-5 border-b border-border px-6') }}>
    @foreach ($tabs as $panel => $title)
        <button
            type="button"
            role="tab"
            id="{{ $panel }}-tab"
            aria-controls="{{ $panel }}"
            aria-selected="{{ $loop->first ? 'true' : 'false' }}"
            @unless ($loop->first) tabindex="-1" @endunless
            class="-mb-px flex items-center border-y-2 border-transparent text-sm text-muted hover:text-ink aria-selected:border-b-accent aria-selected:font-medium aria-selected:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
        >{{ $title }}</button>
    @endforeach
</div>
