<!DOCTYPE html>
<html lang="tr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Sınav Programı' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
    <div class="min-h-full">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center gap-6 px-4 py-3">
                <a href="{{ route('schedule') }}" class="font-semibold tracking-tight">Sınav Programı</a>

                <nav class="flex flex-1 items-center gap-1 text-sm">
                    @auth
                        @if (auth()->user()->canSeeWholeSchedule())
                            <x-nav-link :href="route('schedule')" :active="request()->routeIs('schedule*')">Program</x-nav-link>
                            <x-nav-link :href="route('compare')" :active="request()->routeIs('compare')">Karşılaştır</x-nav-link>
                        @endif

                        <x-nav-link :href="route('invigilation')" :active="request()->routeIs('invigilation')">Gözetmenler</x-nav-link>

                        @can('manage-solutions')
                            <x-nav-link :href="route('solve')" :active="request()->routeIs('solve')">Üret</x-nav-link>
                        @endcan

                        @can('import-data')
                            <x-nav-link :href="route('import')" :active="request()->routeIs('import')">Veri Aktar</x-nav-link>
                        @endcan
                    @endauth
                </nav>

                @auth
                    <div class="flex items-center gap-3 text-sm">
                        <span class="text-slate-500">
                            {{ auth()->user()->name }}
                            <span class="text-slate-400">· {{ auth()->user()->roleLabel() }}</span>
                        </span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="rounded px-2 py-1 text-slate-500 hover:bg-slate-100">Çıkış</button>
                        </form>
                    </div>
                @endauth
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
