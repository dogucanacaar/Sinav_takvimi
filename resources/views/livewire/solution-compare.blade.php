<div>
    <h1 class="mb-1 text-xl font-semibold">Çözümleri karşılaştır</h1>
    <p class="mb-5 text-sm text-slate-500">
        Tek bir ceza puanı hangisinin daha iyi olduğunu söylemez; farkın hangi kuraldan geldiği önemlidir.
    </p>

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        @foreach ([['leftId', 'Sol'], ['rightId', 'Sağ']] as [$model, $label])
            <label class="block">
                <span class="mb-1 block text-sm font-medium">{{ $label }}</span>
                <select wire:model.live="{{ $model }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <option value="">—</option>
                    @foreach ($solutions as $s)
                        <option value="{{ $s->id }}">
                            #{{ $s->id }} · {{ $s->label }} ({{ $s->status }})
                        </option>
                    @endforeach
                </select>
            </label>
        @endforeach
    </div>

    <div class="overflow-hidden rounded border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-4 py-2 font-medium">Ölçüt</th>
                    <th class="px-4 py-2 text-right font-medium">{{ $left?->label ?? '—' }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ $right?->label ?? '—' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as [$label, $a, $b, $better])
                    @php
                        $winner = null;
                        if ($better === 'lower' && $a !== null && $b !== null && $a != $b) {
                            $winner = $a < $b ? 'left' : 'right';
                        }
                    @endphp
                    <tr class="border-t border-slate-100">
                        <td class="px-4 py-2 text-slate-600">{{ $label }}</td>
                        <td class="px-4 py-2 text-right {{ $winner === 'left' ? 'font-semibold text-emerald-700' : '' }}">
                            {{ $this->format($a) }}
                        </td>
                        <td class="px-4 py-2 text-right {{ $winner === 'right' ? 'font-semibold text-emerald-700' : '' }}">
                            {{ $this->format($b) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-slate-400">
        Yeşil, o ölçütte daha iyi olanı gösterir. İterasyon ve süre "daha iyi/kötü" değildir; bilgi amaçlıdır.
    </p>
</div>
