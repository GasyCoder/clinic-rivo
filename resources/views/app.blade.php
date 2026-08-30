<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title inertia>
        {{ config('app.name', 'Clinique Saint Georges') }}
    </title>

    @vite([
        'resources/css/app.css',
        'resources/js/app.js',
    ])

    <x-inertia::head />
</head>

<body class="min-w-[320px] bg-gray-50 font-body text-sm font-normal leading-relaxed text-slate-600 dark:bg-gray-1000 dark:text-slate-300">
    <x-inertia::app />
</body>
</html>
