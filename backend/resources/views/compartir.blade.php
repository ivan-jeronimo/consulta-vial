<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <meta name="description" content="{{ $descripcion }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Andantes del Asfalto">
    <meta property="og:locale" content="es_MX">
    <meta property="og:title" content="{{ $titulo }}">
    <meta property="og:description" content="{{ $descripcion }}">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:image" content="{{ $imagen }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    @if (config('services.facebook.app_id'))
        <meta property="fb:app_id" content="{{ config('services.facebook.app_id') }}">
    @endif
</head>
<body>
    <p><a href="{{ $destino }}">{{ $titulo }}. Juega El reto del asfalto.</a></p>
</body>
</html>
