@php
    $commands = [
        ['id' => 'pages', 'label' => 'Pages', 'group' => 'Navigate', 'href' => '/admin/pages'],
        ['id' => 'collections', 'label' => 'Collections', 'group' => 'Navigate', 'href' => '/admin/collections'],
        ['id' => 'media', 'label' => 'Media library', 'group' => 'Navigate', 'keywords' => 'files images uploads', 'href' => '/admin/media'],
        ['id' => 'settings', 'label' => 'Settings', 'group' => 'Navigate', 'href' => '/admin/settings'],
        ['id' => 'new-page', 'label' => 'New page', 'group' => 'Create', 'keywords' => 'add', 'href' => '/admin/pages/new'],
        ['id' => 'publish', 'label' => 'Publish changes', 'group' => 'Actions', 'keywords' => 'deploy live', 'href' => '/admin/publish'],
    ];
@endphp

{{--
 | There is no top-bar component in the design system: the bar is a row, and a
 | wrapper whose whole body is <header class="...">{{ $slot }}</header> would be
 | an abstraction with one consumer. This is the reference assembly instead, so
 | the shell renders the same bar rather than a copy of it that drifts.
--}}
<header class="flex h-8.5 shrink-0 items-center gap-3 border-b border-border bg-surface px-3">
    {{-- flex-1 is basis-0, so the two outer groups always resolve to the same
         width and the command center lands on the bar's true centre -- not
         wherever the brand and nav happen to end. --}}
    <div class="flex flex-1 items-center gap-2">
        <x-mainstay::logo :size="16" />

        {{-- The items sit nearly flush: each already carries px-2.5, so their
             own padding is the separation and a gap on top of it reads as a
             hole. Wrapped rather than tightening the group's gap, so the mark
             keeps its distance from the first item. --}}
        <div class="flex items-center gap-0.5">
            {{-- Only the outbound link is navigation; a creation menu has no
                 business inside the landmark. --}}
            <nav aria-label="Main" class="flex items-center">
                <x-mainstay::menu-item compact href="https://example.test" target="_blank" rel="noreferrer" class="gap-1.5">
                    Visit website
                    {{-- The icon is decorative, so on its own it tells a screen
                         reader nothing about the new tab it is warning sighted
                         users about. --}}
                    <span class="sr-only">(opens in a new tab)</span>
                    <x-mainstay::external-icon />
                </x-mainstay::menu-item>
            </nav>

            <x-mainstay::dropdown trigger-class="h-6 rounded-control px-2.5 text-xs text-muted hover:bg-canvas hover:text-ink">
                <x-slot:label>New</x-slot:label>

                <x-mainstay::dropdown-item>Page</x-mainstay::dropdown-item>
                <x-mainstay::dropdown-item>Collection</x-mainstay::dropdown-item>
                <x-mainstay::dropdown-item>Media upload</x-mainstay::dropdown-item>
            </x-mainstay::dropdown>
        </div>
    </div>

    <x-mainstay::command-center :commands="$commands" class="w-full max-w-sm" />

    <div class="flex flex-1 justify-end">
        <x-mainstay::user-menu name="Ada Lovelace" email="ada@mainstay.test" />
    </div>
</header>
