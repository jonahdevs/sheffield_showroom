<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        {{-- Ordered widest-support first. The .ico carries 16/32/48 for the browsers and
             OS shell surfaces that still only read .ico; the SVG is listed after it so
             browsers that understand image/svg+xml prefer the vector and stay crisp on
             HiDPI and at odd zoom levels. The explicit 16/32 PNGs are the fallback for
             engines that take neither, and must stay listed or those clients silently
             downscale the .ico's 48 and fringe the flame's thin stem. --}}
        <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon-32x32.png" type="image/png" sizes="32x32">
        <link rel="icon" href="/favicon-16x16.png" type="image/png" sizes="16x16">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
        {{-- Named .json rather than the more usual .webmanifest because nginx (which
             Herd fronts the app with) has no MIME mapping for .webmanifest and serves it
             as application/octet-stream, which Chrome refuses to parse as a manifest.
             The .json extension maps to application/json, which Chrome does accept. --}}
        <link rel="manifest" href="/manifest.json">
        <meta name="theme-color" content="#c12534">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Sheffieldafrica') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
