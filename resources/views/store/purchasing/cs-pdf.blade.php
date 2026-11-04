<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $cs->cs_no }}</title>
    <style>@page { margin: 18px 20px; }</style>
</head>
<body>
    @include('store.purchasing._cs-sheet', ['cs' => $cs])
</body>
</html>
