@php
    $status = $status ?? 404;
    $title = $title ?? 'Page not found';
    $message = $message ?? 'The page you requested is unavailable.';
    $tone = $tone ?? 'default';
    $method = request()->method();
    $path = '/' . ltrim(request()->path(), '/');

    if (auth()->check() && auth()->user()?->hasAnyRole(['Super Admin (OSA)', 'USG Admin', 'LSG Admin', 'Society Admin', 'Academic Unit Admin', 'Office Event Admin', 'HR Admin'])) {
        $homeUrl = route('dashboard');
        $homeLabel = 'Return to Dashboard';
    } elseif (auth()->check() && auth()->user()?->hasRole('Student')) {
        $homeUrl = route('portal');
        $homeLabel = 'Return to Portal';
    } else {
        $homeUrl = route('login');
        $homeLabel = 'Return to Login';
    }
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $status }} - {{ $title }} - EAES</title>
    <meta name="description" content="EAES route status page">
    <link rel="stylesheet" href="{{ asset('css/site-refresh.css') }}">
</head>
<body class="route-error-body">
    <main class="route-error-card" role="alert" aria-live="polite">
        <span class="route-error-code route-error-code--{{ $tone }}">{{ $status }}</span>
        <h1 class="route-error-title">{{ $title }}</h1>
        <p class="route-error-message">{{ $message }}</p>

        <dl class="route-error-meta">
            <div>
                <dt>Request</dt>
                <dd>{{ $method }} {{ $path }}</dd>
            </div>
            <div>
                <dt>System</dt>
                <dd>EAES Portal</dd>
            </div>
        </dl>

        <div class="route-error-actions">
            <a href="{{ $homeUrl }}" class="route-error-button route-error-button--primary">{{ $homeLabel }}</a>
            <button type="button" class="route-error-button" onclick="history.length > 1 ? history.back() : window.location.assign(@js($homeUrl))">
                Go Back
            </button>
        </div>
    </main>
</body>
</html>
