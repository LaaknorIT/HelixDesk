<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'HelixDesk') }}</title>

        <style>
            :root {
                --bg: #f8fafc;
                --surface: #ffffff;
                --border: #e2e8f0;
                --text: #0f172a;
                --muted: #475569;
                --accent: #4f46e5;
                --accent-text: #ffffff;
                --notice-bg: #fef3c7;
                --notice-border: #f59e0b;
                --notice-text: #78350f;
                color-scheme: light dark;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --bg: #0b1120;
                    --surface: #111827;
                    --border: #1f2937;
                    --text: #f1f5f9;
                    --muted: #94a3b8;
                    --accent: #818cf8;
                    --accent-text: #0b1120;
                    --notice-bg: #3b2a0a;
                    --notice-border: #d97706;
                    --notice-text: #fde68a;
                }
            }

            * { box-sizing: border-box; }

            body {
                margin: 0;
                background: var(--bg);
                color: var(--text);
                font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                line-height: 1.6;
            }

            a { color: var(--accent); }

            .wrap { max-width: 64rem; margin: 0 auto; padding: 0 1rem; }

            header .wrap {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                padding-top: 1.25rem;
                padding-bottom: 1.25rem;
            }

            .brand { font-weight: 700; font-size: 1.125rem; text-decoration: none; color: var(--text); }

            .button {
                display: inline-block;
                padding: 0.625rem 1.25rem;
                border-radius: 0.5rem;
                background: var(--accent);
                color: var(--accent-text);
                font-weight: 600;
                text-decoration: none;
                white-space: nowrap;
            }

            .button:hover, .button:focus-visible { filter: brightness(1.1); }

            .hero { padding: 3rem 0 2rem; }
            .hero h1 { margin: 0 0 1rem; font-size: clamp(1.875rem, 5vw, 2.75rem); line-height: 1.15; }
            .hero .intro { max-width: 42rem; color: var(--muted); font-size: 1.125rem; }
            .hero .intro p { margin: 0 0 0.75rem; }
            .hero .button { margin-top: 1rem; }

            .notice {
                margin: 0 0 2rem;
                padding: 1rem 1.25rem;
                border-left: 4px solid var(--notice-border);
                border-radius: 0.5rem;
                background: var(--notice-bg);
                color: var(--notice-text);
            }

            .notice h2 { margin: 0 0 0.25rem; font-size: 1rem; }
            .notice p { margin: 0; }
            .notice a { color: inherit; }

            .sections {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
                gap: 1rem;
                margin: 0 0 2rem;
            }

            .card {
                padding: 1.25rem;
                border: 1px solid var(--border);
                border-radius: 0.75rem;
                background: var(--surface);
            }

            .card h2 { margin: 0 0 0.5rem; font-size: 1.125rem; }
            .card p { margin: 0 0 0.5rem; color: var(--muted); }
            .card p:last-child { margin-bottom: 0; }

            .contact dl { margin: 0; display: grid; grid-template-columns: max-content 1fr; gap: 0.25rem 1rem; }
            .contact dt { font-weight: 600; }
            .contact dd { margin: 0; color: var(--muted); overflow-wrap: anywhere; }

            footer { padding: 2rem 0; color: var(--muted); font-size: 0.875rem; }
        </style>
    </head>
    <body>
        <header>
            <div class="wrap">
                <a class="brand" href="{{ route('home') }}">{{ config('app.name', 'HelixDesk') }}</a>

                @if ($workspaceUrl)
                    <a class="button" href="{{ $workspaceUrl }}">{{ __('Go to your workspace') }}</a>
                @else
                    <a class="button" href="{{ route('login') }}">{{ __('Sign in') }}</a>
                @endif
            </div>
        </header>

        <main class="wrap">
            <section class="hero">
                <h1>{{ $page->headline }}</h1>

                @if ($introduction)
                    <div class="intro">{!! $introduction !!}</div>
                @endif
            </section>

            @if ($page->hasPublicAnnouncement())
                <section class="notice" role="status">
                    @if (filled($page->announcement_title))
                        <h2>{{ $page->announcement_title }}</h2>
                    @endif

                    {!! $announcementBody !!}
                </section>
            @endif

            @if ($sections !== [] || $page->hasPublicContact())
                <div class="sections">
                    @foreach ($sections as $section)
                        <section class="card">
                            @if (filled($section['title']))
                                <h2>{{ $section['title'] }}</h2>
                            @endif

                            {!! $section['body'] !!}
                        </section>
                    @endforeach

                    @if ($page->hasPublicContact())
                        <section class="card contact">
                            <h2>{{ __('Contact us') }}</h2>

                            <dl>
                                @if (filled($page->contact_email))
                                    <dt>{{ __('E-mail') }}</dt>
                                    <dd><a href="mailto:{{ $page->contact_email }}">{{ $page->contact_email }}</a></dd>
                                @endif

                                @if (filled($page->contact_phone))
                                    <dt>{{ __('Phone') }}</dt>
                                    <dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', $page->contact_phone) }}">{{ $page->contact_phone }}</a></dd>
                                @endif

                                @if (filled($page->contact_hours))
                                    <dt>{{ __('Opening hours') }}</dt>
                                    <dd>{{ $page->contact_hours }}</dd>
                                @endif
                            </dl>
                        </section>
                    @endif
                </div>
            @endif
        </main>

        <footer>
            <div class="wrap">{{ config('app.name', 'HelixDesk') }}</div>
        </footer>
    </body>
</html>
