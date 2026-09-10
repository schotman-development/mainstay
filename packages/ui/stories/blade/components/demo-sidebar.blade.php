@props(['current' => null])

@php
    /* One Icon definition, reached from data. The navigation is an array rather
       than markup, so an icon in it is a string -- rendered through the same
       component every other icon goes through rather than a hand-written svg
       that can drift from the house weight. */
    $icon = fn (string $d) => \Illuminate\Support\Facades\Blade::render('<x-mainstay::path-icon :d="$d" />', ['d' => $d]);

    /*
     | A collection and the two things WordPress puts on its flyout: the listing
     | and a blank one. Both hang off the collection's own route, so walking
     | into either of them keeps the collection open in the navigation.
     */
    $collection = fn (string $slug, string $plural, string $singular) => [
        'href' => "/admin/collections/{$slug}",
        'label' => $plural,
        'submenu' => 'flyout',
        'items' => [
            ['href' => "/admin/collections/{$slug}", 'label' => 'View '.strtolower($plural)],
            ['href' => "/admin/collections/{$slug}/new", 'label' => "New {$singular}"],
        ],
    ];

    $sections = [
        [
            /* No group name: the root of the panel is not a category of anything. */
            'items' => [['href' => '/admin', 'label' => 'Dashboard', 'icon' => $icon('M3.25 1.75h9.5a1.5 1.5 0 0 1 1.5 1.5v9.5a1.5 1.5 0 0 1-1.5 1.5h-9.5a1.5 1.5 0 0 1-1.5-1.5v-9.5a1.5 1.5 0 0 1 1.5-1.5zM5.25 10.75V7.5M8 10.75V5.25M10.75 10.75V8.75')]],
        ],
        [
            'label' => 'Content',
            'items' => [
                [
                    'href' => '/admin/collections',
                    'label' => 'Collections',
                    'icon' => $icon('M8 1.75 1.75 5 8 8.25 14.25 5 8 1.75ZM1.75 10.75 8 14 14.25 10.75'),
                    /* Pages is one of these, not a fixture beside them: it is a
                       content type with a listing and a blank one, which is all
                       a collection is. */
                    'items' => [
                        $collection('pages', 'Pages', 'page'),
                        $collection('posts', 'Posts', 'post'),
                        $collection('products', 'Products', 'product'),
                        $collection('events', 'Events', 'event'),
                    ],
                ],
                ['href' => '/admin/media', 'label' => 'Media', 'icon' => $icon('M1.75 11 5.5 7.25l2.75 2.75 2-2 4 4')],
            ],
        ],
        [
            'label' => 'System',
            'items' => [
                ['href' => '/admin/users', 'label' => 'Users', 'icon' => $icon('M2.75 14.25a5.25 5.25 0 0 1 10.5 0M10.75 5.25a2.75 2.75 0 1 1-5.5 0 2.75 2.75 0 0 1 5.5 0')],
                [
                    'href' => '/admin/plugins',
                    'label' => 'Plugins',
                    'icon' => $icon('M6 1.75v3.5M10 1.75v3.5M3.75 5.25h8.5v3a4.25 4.25 0 0 1-8.5 0z'),
                    'items' => [
                        ['href' => '/admin/plugins/seo', 'label' => 'SEO'],
                        ['href' => '/admin/plugins/forms', 'label' => 'Forms'],
                        ['href' => '/admin/plugins/redirects', 'label' => 'Redirects'],
                    ],
                ],
                ['href' => '/admin/settings', 'label' => 'Settings', 'icon' => $icon('M1.75 4.75h12.5M1.75 11.25h12.5')],
            ],
        ],
    ];
@endphp

<x-mainstay::sidebar :sections="$sections" :current="$current" />
