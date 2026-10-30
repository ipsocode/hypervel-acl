<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>ACL{{ config('app.name') ? ' - ' . config('app.name') : '' }}</title>

    <link rel="stylesheet" href="{{ asset('vendor/acl/app.css') }}{{ $version === '' ? '' : '?v=' . $version }}">
</head>
<body>
<div id="acl"></div>

<script>
    window.Acl = {{ Hypervel\Support\Js::from($props) }};
</script>
<script type="module" src="{{ asset('vendor/acl/app.js') }}{{ $version === '' ? '' : '?v=' . $version }}"></script>
</body>
</html>
