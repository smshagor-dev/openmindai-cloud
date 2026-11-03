<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page['title'] }} · {{ $siteName }}</title>
    <style>
        body { margin: 0; background: #f6f8fb; color: #172337; font: 1rem/1.65 system-ui, sans-serif; }
        main { width: min(48rem, calc(100% - 2rem)); margin: 2rem auto; overflow-wrap: anywhere; }
        nav { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .75rem; }
        a { color: #1d4ed8; }
        article :is(p, li) { text-align: justify; hyphens: auto; }
        pre { overflow-x: auto; padding: .75rem; background: #e9eef5; }
        img { max-width: 100%; height: auto; }
        .updated { color: #52627a; }
    </style>
</head>
<body>
<main>
    <nav aria-label="Site links">
        <a href="{{ $frontendUrl }}">{{ $siteName }}</a>
        @if($slug === 'privacy-policy')
            <a href="/terms-and-conditions">Terms and Conditions</a>
        @else
            <a href="/privacy-policy">Privacy Policy</a>
        @endif
    </nav>
    <h1>{{ $page['title'] }}</h1>
    <p class="updated">Updated <time datetime="{{ substr($page['updated_at'], 0, 10) }}">{{ substr($page['updated_at'], 0, 10) }}</time></p>
    <article>{!! $html !!}</article>
    @if($page['support_email'])
        <p>Support contact <a href="mailto:{{ $page['support_email'] }}">{{ $page['support_email'] }}</a></p>
    @endif
</main>
</body>
</html>
