<!DOCTYPE html>
@php($appSettings = app(\App\Services\Settings\AppSettings::class))
{{-- ADR-191 — densité, arrondis, animations, contraste et taille du texte : ceux du site,
     ajustés par ce que l'utilisateur a choisi dans « Mon profil ». Posés dès le rendu
     serveur, pour qu'aucun éclair ne précède leur application. Une taille en %, jamais
     en px : la taille choisie dans le navigateur reste respectée. --}}
@php($appearance = $appSettings->appearance(auth()->user())['effective'])
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-density="{{ $appearance['density'] }}"
    data-radius="{{ $appearance['radius'] }}"
    data-motion="{{ $appearance['motion'] }}"
    data-contrast="{{ $appearance['contrast'] }}"
    @if ($appearance['font_size'] !== \App\Support\Settings\UiOptions::DEFAULT_FONT_SIZE)
        style="font-size: {{ round($appearance['font_size'] / 16 * 100, 3) }}%"
    @endif
>
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    {{-- ADR-184 — masquée aux moteurs de recherche : la même consigne part aussi en en-tête HTTP et dans robots.txt. --}}
    @if ($appSettings->hiddenFromSearchEngines())
        <meta name="robots" content="{{ \App\Services\Settings\AppSettings::ROBOTS_DIRECTIVES }}">
    @endif

    <title inertia>
        {{ $appSettings->brand() }}
    </title>

    {{-- ADR-184 — icône et couleur principale réglées pour ce site. --}}
    @if ($appSettings->iconUrl())
        <link rel="icon" href="{{ $appSettings->iconUrl() }}">
    @endif

    {{-- L'apparence choisie (Clair, Système, Sombre), posée avant le premier affichage :
         sans cela, un poste en sombre voyait un éclair de thème clair à chaque chargement.
         Même clé et même règle que resources/js/stores/theme.js. --}}
    <script>
        (function () {
            try {
                var mode = window.localStorage.getItem('rivo:theme:mode') || 'light';
                var dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            } catch (error) {}
        })();
    </script>

    @vite([
        'resources/css/app.css',
        'resources/js/app.js',
    ])

    {{-- ADR-191 — le thème du site (couleurs des modes clair et sombre, contraste renforcé).
         Construit depuis des couleurs #RRVVBB validées : seulement des chiffres et des %. --}}
    <style id="rivo-theme">{!! $appSettings->themeCss() !!}</style>

    <x-inertia::head />
</head>

<body class="min-w-[320px] bg-background font-body text-sm font-normal leading-relaxed text-slate-600 dark:text-slate-300">
    <x-inertia::app />
</body>
</html>
