<!DOCTYPE html>
<html lang="tr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Giriş — Sınav Programı</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full items-center justify-center bg-slate-50">
    <form method="POST" action="{{ route('login') }}" class="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        <h1 class="mb-1 text-lg font-semibold">Sınav Programı</h1>
        <p class="mb-5 text-sm text-slate-500">Devam etmek için giriş yapın.</p>

        @if (! empty($noUsers))
            <div class="mb-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                <p class="mb-2 font-medium">Henüz hiç kullanıcı tanımlı değil.</p>
                <p class="mb-2">Demo veriyi ve kullanıcıları yüklemek için:</p>
                <code class="block rounded bg-amber-100 px-2 py-1 text-xs">php artisan migrate:fresh --seed</code>
                <p class="mt-2">Ya da tek bir yönetici açmak için:</p>
                <code class="block rounded bg-amber-100 px-2 py-1 text-xs">php artisan kullanici:ekle eposta@ornek.edu.tr</code>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                {{ $errors->first() }}
            </div>
        @endif

        <label class="mb-3 block">
            <span class="mb-1 block text-sm font-medium">E-posta</span>
            <input name="email" type="email" value="{{ old('email') }}" required autofocus
                   class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>

        <label class="mb-4 block">
            <span class="mb-1 block text-sm font-medium">Parola</span>
            <input name="password" type="password" required
                   class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>

        <label class="mb-4 flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" class="rounded border-slate-300"> Beni hatırla
        </label>

        <button class="w-full rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">
            Giriş yap
        </button>
    </form>
</body>
</html>
