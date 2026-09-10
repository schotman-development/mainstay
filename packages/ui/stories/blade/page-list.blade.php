@props(['entries' => 'pages', 'label' => 'Pages', 'action' => 'New page', 'view' => []])

@php
    $rows = match ($entries) {
        'posts' => \Mainstay\Ui\Demo\Entries::posts(),
        'many' => \Mainstay\Ui\Demo\Entries::many(),
        'none' => [],
        default => \Mainstay\Ui\Demo\Entries::pages(),
    };
@endphp

<div class="-m-6 h-dvh">
    <x-stories::page-list :entries="$rows" :label="$label" :view="$view">
        <x-slot:action>
            @if ($action)
                <x-mainstay::button>{{ $action }}</x-mainstay::button>
            @endif
        </x-slot:action>
    </x-stories::page-list>
</div>
