<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Eventia — USM Event Attendance & Evaluation System' }}</title>
    <meta name="description" content="Eventia — University of Southern Mindanao Event Attendance and Evaluation System">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@600;700;800;900&family=Fira+Code:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="{{ asset('images/eventia-logo.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/site-refresh.css') }}">
</head>
<body class="guest-shell min-h-dvh flex items-center justify-center bg-(--color-surface-raised)">

    <div class="guest-card-wrap">
        {{ $slot }}
    </div>

</body>
</html>
