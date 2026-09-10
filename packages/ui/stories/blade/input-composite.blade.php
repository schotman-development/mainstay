{{--
 | The reason `bare` exists. The ring belongs to the shell, so focusing the
 | input lights the box the user thinks they are typing in -- not a smaller box
 | inside it. Click either one.
--}}
<div class="space-y-2">
    <x-mainstay::input-shell class="flex items-stretch">
        <span class="flex select-none items-center border-r border-border px-2.5 font-mono text-xs text-muted">example.test</span>
        <x-mainstay::input bare value="/blog/an-entry" class="min-w-0 rounded-r-control font-mono text-xs" />
    </x-mainstay::input-shell>

    <x-mainstay::tag-input name="tags" :tags="['editor', 'release']" />
</div>
