@props(['current' => '/admin/collections/pages', 'breadcrumb' => [['label' => 'Collections', 'href' => '/admin/collections'], ['label' => 'Pages']]])

<div class="-m-6 h-dvh">
    <x-stories::shell :current="$current" :breadcrumb="$breadcrumb">
        {{--
         | A stand-in rather than a real screen. Every screen brings this shell
         | with it -- it has to, because the bar's actions come from state only
         | the screen holds -- so rendering one here would point the dependency
         | back the way it came. What is left is a story of the contract itself:
         | the box a screen is handed, and how much of the window is left for it.
        --}}
        <div class="grid h-full place-content-center bg-canvas px-4 text-center text-muted">
            <p class="text-sm">The screen renders here.</p>
            <p class="pt-1 text-xs">Full height, no width cap, no padding, no card.</p>
        </div>
    </x-stories::shell>
</div>
