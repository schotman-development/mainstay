@php
    $commands = [
        ['id' => 'pages', 'label' => 'Pages', 'group' => 'Navigate', 'href' => '/admin/pages'],
        ['id' => 'collections', 'label' => 'Collections', 'group' => 'Navigate', 'href' => '/admin/collections'],
        ['id' => 'media', 'label' => 'Media library', 'group' => 'Navigate', 'keywords' => 'files images uploads', 'href' => '/admin/media'],
        ['id' => 'settings', 'label' => 'Settings', 'group' => 'Navigate', 'href' => '/admin/settings'],
        ['id' => 'new-page', 'label' => 'New page', 'group' => 'Create', 'keywords' => 'add', 'href' => '/admin/pages/new'],
    ];
@endphp

{{-- The shipped bar with the workshop's commands and user. --}}
<x-mainstay::top-bar :commands="$commands" website="https://example.test" name="Ada Lovelace" email="ada@mainstay.test" />
