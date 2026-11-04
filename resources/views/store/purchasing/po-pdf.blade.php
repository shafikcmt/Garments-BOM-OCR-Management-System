<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $order->po_no }}</title>
    <style>@page { margin: 28px 30px; }</style>
</head>
<body>
    @include('store.purchasing._po-sheet', ['order' => $order])
</body>
</html>
