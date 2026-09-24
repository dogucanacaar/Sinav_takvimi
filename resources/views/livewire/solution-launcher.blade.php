<div @if ($watched && in_array($watched->status, ['queued', 'running'])) wire:poll.2s @endif>
    <h1 class="mb-1 text-xl font-semibold">Program üret</h1>
    <p class="mb-5 text-sm text-slate-500">
        Üretim arka planda çalışır; bu sayfayı kapatabilirsiniz.
    </p>

    <div class="grid gap-6 lg:grid-cols-[360px_1fr]">
        <form wire:submit="launch" class="space-y-4 rounded border border-slate-200 bg-white p-4">
            <label class="block">
                <span class="mb-1 block text-sm font-medium">Etiket</span>
                <input wire:model="label" type="text" placeholder="Haziran 2026 — 1. deneme"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </label>

            <div class="grid grid-cols-2 gap-3">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Tohum</span>
                    <input wire:model="seed" type="number" placeholder="boş = rastgele"
                           class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <span class="mt-1 block text-xs text-slate-400">Aynı tohum aynı sonucu verir.</span>
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">İterasyon</span>
                    <input wire:model="maxIter" type="number"
                           class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Başlangıç sıcaklığı</span>
                    <input wire:model="startTemp" type="number" step="1"
                           class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Soğuma</span>
                    <input wire:model="cooling" type="number" step="0.00001"
                           class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </label>
            </div>

            <fieldset class="rounded border border-slate-200 p-3">
                <legend class="px-1 text-sm font-medium">Ceza ağırlıkları</legend>
                <div class="grid grid-cols-2 gap-3">
                    @foreach (['E1' => 'Aynı gün birden fazla', 'E2' => 'Art arda oturum', 'E3' => 'Farklı bina', 'E4' => 'Kapasite israfı'] as $code => $label)
                        <label class="block">
                            <span class="mb-1 block text-xs text-slate-500">{{ $code }} — {{ $label }}</span>
                            <input wire:model="weights.{{ $code }}" type="number"
                                   class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @error('maxIter') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
            @error('cooling') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror

            {{-- wire:target olmadan wire:loading, bileşenin HER isteğinde
                 (wire:poll dahil) devreye girer; düğme iki saniyede bir
                 kendiliğinden pasifleşir ve o ana denk gelen tıklama
                 kaybolur. Hedef açıkça yazılmalı. --}}
            <button type="submit" aria-label="Üret"
                    class="w-full rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-60"
                    wire:loading.attr="disabled" wire:target="launch">
                <span wire:loading.remove wire:target="launch">Üret</span>
                <span wire:loading wire:target="launch">Kuyruğa bırakılıyor…</span>
            </button>
        </form>

        <div class="space-y-4">
            @if ($watched)
                <div class="rounded border border-slate-200 bg-white p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <h2 class="font-medium">{{ $watched->label }}</h2>
                        <span class="rounded-full px-2 py-0.5 text-xs
                            {{ $watched->status === 'completed' ? 'bg-emerald-100 text-emerald-700'
                             : ($watched->status === 'failed' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">
                            {{ $watched->status }}
                        </span>
                    </div>

                    @if ($stalled)
                        <div class="mb-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            <p class="mb-1 font-medium">İş kuyruktan alınmıyor.</p>
                            <p class="mb-2">
                                Kuyruk işçisi çalışmıyor olabilir. Durumu görmek için:
                                <code class="rounded bg-amber-100 px-1">docker compose logs worker --tail 20</code>
                            </p>
                            {{-- Yedek yol: çözümü bu istek içinde üret.
                                 Dakikalarca sürebilir; Livewire aynı bileşenin
                                 isteklerini sıraya aldığı için yoklama bu
                                 sürede beklemeye geçer. --}}
                            <button type="button" wire:click="runNow"
                                    aria-label="Kuyruğu beklemeden şimdi üret"
                                    wire:loading.attr="disabled" wire:target="runNow"
                                    class="rounded bg-amber-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-800 disabled:opacity-60">
                                <span wire:loading.remove wire:target="runNow">Kuyruğu beklemeden şimdi üret</span>
                                <span wire:loading wire:target="runNow">Üretiliyor, bekleyin… (sayfayı kapatmayın)</span>
                            </button>
                        </div>
                    @endif

                    @if ($failure)
                        <div class="mb-3 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                            {{ $failure }}
                        </div>
                    @endif

                    @if ($progress)
                        @php($percent = min(100, round($progress['iter'] / max(1, $progress['max_iter']) * 100)))
                        <div class="mb-2 h-2 w-full overflow-hidden rounded bg-slate-100">
                            <div class="h-full bg-slate-900" style="width: {{ $percent }}%"></div>
                        </div>
                        <p class="text-sm text-slate-500">
                            {{ number_format($progress['iter']) }} / {{ number_format($progress['max_iter']) }} iterasyon ·
                            en iyi ceza <strong>{{ number_format($progress['best']) }}</strong> ·
                            sıcaklık {{ $progress['temp'] }}
                        </p>
                    @elseif ($watched->status === 'completed')
                        <p class="text-sm text-slate-600">
                            Ceza puanı <strong>{{ number_format($watched->penalty) }}</strong>
                            ({{ $watched->stats['improvement_percent'] ?? 0 }}% iyileşme,
                            {{ $watched->stats['elapsed_seconds'] ?? '-' }} sn)
                        </p>
                        <a href="{{ route('schedule.show', $watched) }}" class="mt-2 inline-block text-sm underline">Programı aç</a>
                    @elseif ($watched->status === 'failed')
                        <p class="text-sm text-rose-700">{{ $watched->failure_reason }}</p>
                    @else
                        <p class="text-sm text-slate-500">Kuyrukta bekliyor…</p>
                    @endif
                </div>
            @endif

            <div class="rounded border border-slate-200 bg-white">
                <h2 class="border-b border-slate-200 px-4 py-2 font-medium">Son çözümler</h2>
                <table class="w-full text-sm">
                    <thead class="text-left text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Etiket</th>
                            <th class="px-4 py-2 font-medium">Durum</th>
                            <th class="px-4 py-2 text-right font-medium">Ceza</th>
                            <th class="px-4 py-2 text-right font-medium">Süre</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($solutions as $s)
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-2">{{ $s->label }}</td>
                                <td class="px-4 py-2 text-slate-500">{{ $s->status }}</td>
                                <td class="px-4 py-2 text-right">{{ $s->penalty !== null ? number_format($s->penalty) : '—' }}</td>
                                <td class="px-4 py-2 text-right text-slate-500">{{ $s->stats['elapsed_seconds'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if ($s->status === 'completed')
                                        <a href="{{ route('schedule.show', $s) }}" class="underline">aç</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">Henüz çözüm yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
