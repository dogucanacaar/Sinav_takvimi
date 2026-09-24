<div>
    <div class="mb-5 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold">Veri aktar</h1>
            <p class="text-sm text-slate-500">
                Dosya önce doğrulanır, hiçbir şey yazılmadan rapor gösterilir. Yazma işlemi sizin onayınızla başlar.
            </p>
        </div>
        <button wire:click="downloadTemplate"
                class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100">
            Şablonu indir
        </button>
    </div>

    <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="space-y-4">
            <form wire:submit="validateFile" class="rounded border border-slate-200 bg-white p-4">
                <label class="mb-3 block">
                    <span class="mb-1 block text-sm font-medium">Dosya (.xlsx veya .csv)</span>
                    <input wire:model="file" type="file"
                           class="block w-full text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:text-white">
                </label>

                @error('file') <p class="mb-3 text-sm text-rose-600">{{ $message }}</p> @enderror

                <label class="mb-3 flex items-center gap-2 text-sm">
                    <input wire:model="replace" type="checkbox" class="rounded border-slate-300">
                    Mevcut veriyi sil ve yerine yaz
                </label>

                <button class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                        wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="validateFile,file">Doğrula</span>
                    <span wire:loading wire:target="validateFile,file">Okunuyor…</span>
                </button>
            </form>

            @if ($failure)
                <div class="rounded border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $failure }}</div>
            @endif

            @if ($result)
                <div class="rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    Aktarım tamamlandı — {{ $result }}
                </div>
            @endif

            @if ($summary)
                <div class="rounded border border-slate-200 bg-white p-4">
                    <h2 class="mb-3 font-medium">Dosyada bulunanlar</h2>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                        @foreach ($summary as $key => $value)
                            <div class="flex justify-between border-b border-slate-100 py-1">
                                <dt class="text-slate-500">{{ str_replace('_', ' ', $key) }}</dt>
                                <dd class="font-medium">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($rowErrors === [])
                        <button wire:click="apply"
                                class="mt-4 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                                wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="apply">Onayla ve aktar</span>
                            <span wire:loading wire:target="apply">Yazılıyor…</span>
                        </button>
                    @else
                        <p class="mt-4 text-sm text-rose-700">
                            {{ count($rowErrors) }} hata var. Hatalar düzeltilmeden aktarım yapılmaz.
                        </p>
                    @endif
                </div>
            @endif

            @foreach ([['Hatalar', $rowErrors, 'rose'], ['Uyarılar', $rowWarnings, 'amber']] as [$heading, $list, $color])
                @if ($list !== [])
                    <div class="rounded border border-slate-200 bg-white">
                        <h2 class="border-b border-slate-200 px-4 py-2 font-medium">
                            {{ $heading }} <span class="text-slate-400">({{ count($list) }})</span>
                        </h2>
                        <ul class="max-h-96 divide-y divide-slate-100 overflow-y-auto text-sm">
                            @foreach ($list as $item)
                                <li class="px-4 py-2">
                                    <span class="text-{{ $color }}-700">
                                        {{ $item['sheet'] }}@if ($item['row']), {{ $item['row'] }}. satır @endif
                                        @if ($item['column']) <span class="text-slate-400">({{ $item['column'] }})</span> @endif
                                    </span>
                                    <span class="text-slate-600">{{ $item['message'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        </div>

        <aside class="rounded border border-slate-200 bg-white p-4 text-sm">
            <h2 class="mb-2 font-medium">Beklenen sayfalar</h2>
            <p class="mb-3 text-slate-500">
                Sayfa ve sütun adları esnektir: büyük/küçük harf, Türkçe karakter ve alt çizgi farkı sorun değil.
            </p>
            <dl class="space-y-2">
                @foreach ($expected as $sheet => $columns)
                    <div>
                        <dt class="font-medium">{{ $sheet }}</dt>
                        <dd class="text-slate-500">{{ implode(' · ', $columns) }}</dd>
                    </div>
                @endforeach
            </dl>
        </aside>
    </div>
</div>
