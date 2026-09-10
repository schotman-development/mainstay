<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="mainstay-api" content="{{ url(config('mainstay.api.prefix')) }}">
    <title>Mainstay</title>
    <link rel="stylesheet" href="{{ asset('vendor/mainstay/mainstay.css') }}?v={{ \Mainstay\Mainstay::VERSION }}">
</head>
<body>
    <div class="flex h-dvh flex-col bg-canvas font-sans text-ink">
        <header class="flex shrink-0 items-center justify-between border-b border-border px-6 py-3">
            <div class="flex items-center gap-2">
                <x-mainstay::logo :size="16" />
                {{-- The admin is just another client of the public content API,
                     so the panel says so by reading its own version back out of
                     it rather than off the server that rendered this page. --}}
                <span id="mainstay-status" class="text-xs text-muted">connecting&hellip;</span>
            </div>

            <x-mainstay::button variant="secondary" disabled>Publish</x-mainstay::button>
        </header>

        <div class="flex min-h-0 flex-1">
            {{-- The request URI, not getPathInfo(): the hrefs come from route()
                 and carry the application's base path, so a `current` with it
                 stripped would match nothing on an app served from a
                 subdirectory. --}}
            <x-mainstay::sidebar :sections="\Mainstay\Navigation::sections()" :current="request()->getRequestUri()" />

            <main class="min-w-0 flex-1 overflow-y-auto px-6 py-10">
                <div class="mx-auto max-w-3xl">
                    {{-- The one island. Everything around it is markup the
                         server already knows how to draw. --}}
                    <div id="mainstay-editor" class="min-h-64 rounded-control border border-border bg-surface p-4"></div>
                </div>
            </main>
        </div>
    </div>

    <script type="module" src="{{ asset('vendor/mainstay/mainstay.js') }}?v={{ \Mainstay\Mainstay::VERSION }}"></script>
</body>
</html>
