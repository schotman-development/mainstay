@props(['title' => null, 'breadcrumb' => [], 'actions' => null])

@php
    $user = \Mainstay\Auth\Gate::user();
    $site = array_values(\Mainstay\Facades\Mainstay::locales())[0];
@endphp

{{-- Every signed-in screen: the document, the shell, the bar and the
     navigation, with the screen's own title, trail and actions. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}Mainstay</title>
    <link rel="stylesheet" href="{{ asset('vendor/mainstay/mainstay.css') }}?v={{ \Mainstay\Mainstay::VERSION }}">
</head>
<body class="h-full">
    <x-mainstay::shell :breadcrumb="$breadcrumb" :actions="$actions">
        <x-slot:bar>
            <x-mainstay::top-bar
                :commands="\Mainstay\Navigation::commands()"
                :website="$site['host'] === null ? url($site['prefix'] ?: '/') : $site['origin'].'/'"
                :name="$user->name"
                :email="$user->email"
            >
                <form method="POST" action="{{ route('mainstay.logout') }}">
                    @csrf
                    <x-mainstay::dropdown-item type="submit">Sign out</x-mainstay::dropdown-item>
                </form>
            </x-mainstay::top-bar>
        </x-slot:bar>

        <x-slot:navigation>
            {{-- The request URI, not getPathInfo(): the hrefs come from route()
                 and carry the application's base path, so a `current` with it
                 stripped would match nothing on an app served from a
                 subdirectory. --}}
            <x-mainstay::sidebar :sections="\Mainstay\Navigation::sections()" :current="request()->getRequestUri()" />
        </x-slot:navigation>

        {{ $slot }}
    </x-mainstay::shell>

    {{-- Dialogs a field draws, out here where no form holds them, so each
         can have a form of its own. --}}
    @stack('dialogs')

    <script type="module" src="{{ asset('vendor/mainstay/mainstay.js') }}?v={{ \Mainstay\Mainstay::VERSION }}"></script>
</body>
</html>
