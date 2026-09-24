<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Gözetmen görevleri</h1>
            @if ($solution)
                <p class="text-sm text-slate-500">
                    {{ $solution->label }}
                    @if ($sapma = $solution->stats['invigilation']['sapma_sonra'] ?? null)
                        · yük sapması {{ $sapma }}
                    @endif
                </p>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($solution && auth()->user()->canSeeWholeSchedule())
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Öğretim üyesi ara"
                       class="w-56 rounded border border-slate-300 px-3 py-1.5 text-sm">
            @endif

            {{-- Yazdırma öğretim üyesine de açık: PrintController sorguyu
                 zaten kendi görevleriyle sınırlıyor, dolayısıyla çıktı
                 başkasının görevini göstermez. Kendi görev listesini
                 kâğıda dökememek, ekranı görüp yazamamak demekti. --}}
            @if ($solution)
                <a href="{{ route('print.invigilators', $solution) }}" target="_blank"
                   class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100">Yazdır</a>
            @endif
        </div>
    </div>

    @if ($duties->isEmpty())
        <div class="rounded border border-slate-200 bg-white p-8 text-center text-slate-500">
            Gösterilecek görev yok.
        </div>
    @else
        <div class="space-y-3">
            @foreach ($duties as $name => $rows)
                <div class="overflow-hidden rounded border border-slate-200 bg-white">
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-2">
                        <h2 class="font-medium">
                            {{ $rows->first()->title }} {{ $name }}
                        </h2>
                        <span class="text-sm text-slate-500">{{ $rows->count() }} görev</span>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="text-left text-slate-500">
                            <tr>
                                <th class="px-4 py-1.5 font-medium">Tarih</th>
                                <th class="px-4 py-1.5 font-medium">Saat</th>
                                <th class="px-4 py-1.5 font-medium">Derslik</th>
                                <th class="px-4 py-1.5 font-medium">Ders</th>
                                <th class="px-4 py-1.5 text-right font-medium">Öğrenci</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $duty)
                                <tr class="border-t border-slate-100">
                                    <td class="px-4 py-1.5">{{ \Carbon\Carbon::parse($duty->day)->format('d.m.Y') }}</td>
                                    <td class="px-4 py-1.5">{{ substr($duty->starts_at, 0, 5) }}</td>
                                    <td class="px-4 py-1.5">{{ $duty->room_name }}</td>
                                    <td class="px-4 py-1.5">
                                        <span class="font-medium">{{ $duty->code }}</span>
                                        <span class="text-slate-500">{{ $duty->course_name }}</span>
                                    </td>
                                    <td class="px-4 py-1.5 text-right text-slate-500">{{ $duty->student_count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    @endif
</div>
