@props(['commands' => [], 'website' => null, 'name', 'email' => null])

{{--
 | The bar across the top of every screen: where you are going (the website,
 | the command centre) and who you are. The user menu's items are the slot,
 | since signing out is a form only the screen that draws this can post.
--}}
<header class="flex h-8.5 shrink-0 items-center gap-3 border-b border-border bg-surface px-3">
    {{-- flex-1 is basis-0, so the two outer groups always resolve to the same
         width and the command center lands on the bar's true centre -- not
         wherever the brand and nav happen to end. --}}
    <div class="flex flex-1 items-center gap-2">
        <x-mainstay::logo :size="16" />

        @if ($website)
            <nav aria-label="Main" class="flex items-center">
                <x-mainstay::menu-item compact :href="$website" target="_blank" rel="noreferrer" class="gap-1.5">
                    Visit website
                    {{-- The icon is decorative, so on its own it tells a screen
                         reader nothing about the new tab it is warning sighted
                         users about. --}}
                    <span class="sr-only">(opens in a new tab)</span>
                    <x-mainstay::external-icon />
                </x-mainstay::menu-item>
            </nav>
        @endif
    </div>

    <x-mainstay::command-center :commands="$commands" class="w-full max-w-sm" />

    <div class="flex flex-1 justify-end">
        <x-mainstay::user-menu :name="$name" :email="$email">{{ $slot }}</x-mainstay::user-menu>
    </div>
</header>
