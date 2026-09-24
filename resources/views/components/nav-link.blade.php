@props(['active' => false])

<a {{ $attributes->merge([
    'class' => 'rounded px-3 py-1.5 transition '.($active
        ? 'bg-slate-900 text-white'
        : 'text-slate-600 hover:bg-slate-100'),
]) }}>{{ $slot }}</a>
