<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin panel
    |--------------------------------------------------------------------------
    |
    | Where the admin single-page app is mounted. Set a domain to serve it from
    | a dedicated hostname; leave it null to mount on the application's domain.
    |
    */

    'domain' => env('MAINSTAY_DOMAIN'),

    'path' => env('MAINSTAY_PATH', 'admin'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Content API
    |--------------------------------------------------------------------------
    |
    | Mainstay is headless: this API is the contract every consumer uses, from
    | the admin panel to the static site generator that builds your front end.
    |
    */

    'api' => [
        'prefix' => env('MAINSTAY_API_PREFIX', 'api/mainstay'),

        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public pages
    |--------------------------------------------------------------------------
    |
    | Every GET no route of the application answers is looked up as an entry's
    | path and rendered in the entry's view. Turn it off where the front end
    | is built elsewhere, and the application's own fallback route runs again.
    |
    */

    'site' => [
        'enabled' => true,

        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content locales
    |--------------------------------------------------------------------------
    |
    | The languages content is written in, the first being the default, each
    | with where it is served: a path prefix, a host, or both. Either every
    | locale names a host or none does.
    |
    |     ['en' => '/', 'nl' => '/nl']
    |     ['en' => 'https://example.com', 'nl' => 'https://example.nl']
    |
    | Only a field declared localized differs between them. This is not the
    | application's locale: that one follows the visitor, and a public page
    | sets it to the locale the page is served in.
    |
    */

    'locales' => [env('APP_LOCALE', 'en') => '/'],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | Where uploaded images are kept. The copies an image field's sizes name
    | go to a public disk and are linked to as plain files; the original goes
    | to a private one and is never served, since it still carries the
    | camera's EXIF, GPS included.
    |
    */

    'media' => [
        'disk' => env('MAINSTAY_MEDIA_DISK', 'public'),

        'originals' => env('MAINSTAY_MEDIA_ORIGINALS', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema sync
    |--------------------------------------------------------------------------
    |
    | mainstay:sync alters the database to match the declared content types,
    | and drops the column of any property that was renamed or removed. Turn
    | it on in development only; it refuses to run in production regardless.
    |
    */

    'schema' => [
        'sync' => env('MAINSTAY_SCHEMA_SYNC', false),
    ],

];
