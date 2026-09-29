<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — Daleachious Cafe</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/legal.css') }}">
</head>
<body>
    <div class="page">
        <header class="hero">
            <div class="hero-inner">
                <p class="brand">Daleachious Cafe</p>
                <h1>{{ $title }}</h1>
                <p class="hero-meta">Effective date: September 29, 2026</p>
            </div>
        </header>

        <main class="content">
            @yield('content')
        </main>

        <footer class="footer">
            <p>
                &copy; {{ date('Y') }} Daleachious Cafe
                · <a href="{{ url('/privacy-policy') }}">Privacy Policy</a>
                · <a href="{{ url('/terms') }}">Terms</a>
                · <a href="https://daleachious.cloud" target="_blank" rel="noopener noreferrer">daleachious.cloud</a>
            </p>
        </footer>
    </div>
</body>
</html>
