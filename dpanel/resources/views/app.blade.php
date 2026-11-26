<!DOCTYPE html>
@php($brandName = data_get($page, 'props.branding.name') ?: config('app.name', 'dPanel'))
@php($brandColor = data_get($page, 'props.branding.color'))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($brandColor) style="--brand-color: {{ $brandColor }}" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#03060f">

        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">
        <link rel="shortcut icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">

        <!-- PWA -->
        <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
        <link rel="apple-touch-icon" href="{{ asset('pwa/apple-touch-icon.png') }}">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ $brandName }}">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

        <title inertia>{{ $brandName }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        <link href="{{ asset('assets/bs-icon/bootstrap-icons.min.css') }}" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        {{-- The panel waits for English (its fallback language) before it
             mounts, and that bundle is a chunk of its own so the other four
             languages stay out of the entry file: preloading it here starts
             the download beside app.js instead of after it. rescue() keeps
             the dev server, and a build made before this chunk existed,
             to no preload rather than an error. --}}
        @php($localeChunk = file_exists(public_path('hot')) ? null : rescue(fn () => \Illuminate\Support\Facades\Vite::asset('resources/js/i18n/locales/en/index.js'), null, false))
        @if ($localeChunk)
            <link rel="modulepreload" href="{{ $localeChunk }}" />
        @endif
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
