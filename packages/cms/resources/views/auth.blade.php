<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} &middot; Mainstay</title>
    {{-- The stylesheet alone: there is no reason to ship the admin's script
         to someone who is not signed in yet. --}}
    <link rel="stylesheet" href="{{ asset('vendor/mainstay/mainstay.css') }}?v={{ \Mainstay\Mainstay::VERSION }}">
</head>
<body class="bg-canvas font-sans text-ink">
    <main class="flex min-h-dvh flex-col items-center justify-center gap-6 px-4 py-10">
        <x-mainstay::logo :size="20" />

        <div class="w-full max-w-sm overflow-hidden rounded-control border border-border bg-surface">
            <h1 class="border-b border-border px-4 py-3.5 text-sm font-semibold">{{ $title }}</h1>

            @if (session('status'))
                <p role="status" class="border-b border-border px-4 py-3.5 text-sm">{{ session('status') }}</p>
            @endif

            @yield('form')
        </div>

        @yield('after')
    </main>
</body>
</html>
