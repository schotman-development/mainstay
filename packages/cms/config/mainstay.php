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

];
