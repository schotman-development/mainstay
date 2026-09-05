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
    <div id="mainstay"></div>
    <script type="module" src="{{ asset('vendor/mainstay/mainstay.js') }}?v={{ \Mainstay\Mainstay::VERSION }}"></script>
</body>
</html>
