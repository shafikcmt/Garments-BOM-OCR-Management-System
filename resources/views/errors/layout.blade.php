{{--
    Error page shell.

    Deliberately self-contained rather than extending layouts.app: that layout
    renders the sidebar and queries notifications for auth()->user(), so a 500
    hit by a guest — or an error raised while the session is unusable — would
    crash while trying to render the page explaining the crash.

    Only the Vite bundle is pulled in, and even that degrades: the page is
    readable with no CSS at all.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') — Humana Apparels Pvt. Ltd</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="gx-error-body">
    <main class="gx-error">
        <div class="gx-error-card">
            <div class="gx-error-code @yield('tone', 'is-neutral')">@yield('code')</div>

            <h1 class="gx-error-title">@yield('title')</h1>

            <p class="gx-error-text">@yield('message')</p>

            @hasSection('detail')
                <div class="gx-error-detail">@yield('detail')</div>
            @endif

            <div class="gx-error-actions">
                @auth
                    <a href="{{ url('/dashboard') }}" class="btn btn-primary">Back to dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-primary">Sign in</a>
                @endauth

                <button type="button" class="btn btn-outline-secondary" onclick="history.back()">Go back</button>
            </div>

            @auth
                <p class="gx-error-signed">
                    Signed in as {{ auth()->user()->name }}
                    @if(auth()->user()->getRoleNames()->isNotEmpty())
                        ({{ auth()->user()->getRoleNames()->map(fn ($r) => \Illuminate\Support\Str::headline($r))->implode(', ') }})
                    @endif
                </p>
            @endauth
        </div>
    </main>
</body>
</html>
